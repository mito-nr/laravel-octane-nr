# Laravel Octane (Swoole) + New Relic PHP agent + OpenTelemetry

PHP 8.2 / Laravel 10 / Laravel Octane 1.5.6 / Swoole 5.1.1 の検証環境。
1つのアプリから **New Relic PHP エージェント** と **OpenTelemetry (OTLP)** の両方で New Relic にデータを送ります。

## 構成

| コンテナ | 内容 |
|---|---|
| app   | php:8.2-cli + Swoole 5.1.1 + phpredis + ext-opentelemetry + New Relic PHP agent 12.12.0.41 (+ newrelic-daemon) |
| mysql | MySQL 8.0 |
| redis | Redis 7 |

New Relic 上では別エンティティとして表示されます。

- PHP エージェント: `laravel-octane-nr-agent` (APM)
- OpenTelemetry: `laravel-octane-nr-otel` (Services - OpenTelemetry)

## 起動

```sh
cp .env.example .env      # NEW_RELIC_LICENSE_KEY に INGEST - LICENSE キーを設定
docker compose up -d --build
./scripts/load.sh 100     # 負荷をかける
```

EU リージョンの場合は `OTEL_EXPORTER_OTLP_ENDPOINT=https://otlp.eu01.nr-data.net:4318` に変更してください。

## エンドポイント

| パス | 内容 |
|---|---|
| `GET /` | PHP / Swoole / 各拡張のバージョン |
| `GET /api/sequential?n=2` | DB と Redis の処理を逐次実行(ベースライン) |
| `GET /api/parallel?n=2` | 同じ処理を `Octane::concurrently()` で Swoole タスクワーカーに並列実行 |
| `GET /api/error` | 例外を発生させてエラー連携を確認 |

1件のタスクで約 300ms かかります(DB: `SELECT SLEEP(0.3)`+INSERT+COUNT、Redis: INCR/SET+`BLPOP` 0.3秒)。
`n` は 1〜8 で、1回のリクエストで `2n` 個のタスクを実行します。タスクワーカー数は `OCTANE_TASK_WORKERS`(デフォルト 4)です。

実測値(n=3、ローカル Mac、ライセンスキー未設定):

| | sequential | parallel |
|---|---|---|
| OTel 有効  | 約 2.0s | 約 0.8s |
| OTel 無効  | 約 2.0s | 約 0.7s |

## 計装の仕組み(Octane 特有のポイント)

### 1. リクエスト単位のトランザクション/span — `app/Http/Middleware/RequestTelemetry.php`
- **PHP エージェント**: エージェントは「1プロセス = 1トランザクション」が前提で、Octane のワーカーは常駐するため、
  `newrelic_end_transaction(true)` → `newrelic_start_transaction()` → `newrelic_name_transaction()` → `newrelic_end_transaction()`
  でリクエストごとに明示的に区切ります。
- **OpenTelemetry**: Laravel の自動計装は Octane 下で SERVER span を作らないため、ミドルウェアで `KIND_SERVER` の span を作ります。
  受け取った `traceparent` もここで extract します。
- 例外は Laravel のルーティング Pipeline がレスポンスに変換するため、`$response->exception` から拾って両方に報告します。

### 2. 並列タスクへのコンテキスト伝搬 — `app/Support/Telemetry.php`
`Octane::concurrently()` のクロージャは **別プロセス(Swoole タスクワーカー)** で動くので、トレースコンテキストを明示的に渡します。

- OTel: `TraceContextPropagator::inject()` で作ったキャリアを渡し、タスク側で `extract()` して親に設定します。
- New Relic: `newrelic_insert_distributed_trace_headers()` で作ったヘッダーを渡し、タスク側で
  `newrelic_start_transaction()` + `newrelic_accept_distributed_trace_headers()` を呼びます。
  タスクは `Task/task.db` などの名前の非Webトランザクションとして記録され、分散トレースでリクエストに紐づく想定です(ライセンスキーを設定した環境で要確認)。

OTel のトレースツリー(コンソールエクスポーターで確認済み):

```
GET api/parallel            (SERVER, リクエストワーカー)
└─ demo.parallel
   ├─ task.db               (タスクワーカー pid=A)
   │  └─ PDO::prepare / PDOStatement::execute ...   (auto-pdo)
   └─ task.redis            (タスクワーカー pid=B)
```

### 3. OTel のエクスポートタイミング
Octane のワーカーは終了せず、PHP の batch processor にはバックグラウンドタイマーがありません。そのため
`OTEL_PHP_TRACES_PROCESSOR=batch` にしたうえで、次のタイミングで `forceFlush()` します。

- HTTP: ミドルウェアの `terminate()`(レスポンス送信後)
- タスク: タスク終了時。この flush の時間はタスク時間に含まれます

`simple` プロセッサは span ごとに同期 HTTP 送信するため、レスポンスが大幅に遅くなります(検証時は parallel が約 0.4s → 約 2s に悪化)。

## 片方ずつ切り替えて比較

```sh
NEW_RELIC_ENABLED=false docker compose up -d   # OTel のみ
OTEL_SDK_DISABLED=true  docker compose up -d   # PHP エージェントのみ
docker compose up -d                           # 両方
```

## New Relic での確認クエリ例

```sql
-- PHP エージェント
SELECT count(*), average(duration) FROM Transaction WHERE appName = 'laravel-octane-nr-agent' FACET name SINCE 30 minutes ago
-- OpenTelemetry
SELECT count(*), average(duration.ms) FROM Span WHERE service.name = 'laravel-octane-nr-otel' FACET name SINCE 30 minutes ago
-- エラー
SELECT * FROM TransactionError WHERE appName = 'laravel-octane-nr-agent' SINCE 30 minutes ago
```

## 注意
- Octane 1.5.6 は Laravel 10 までの対応です。Laravel 10 の全リリースにセキュリティアドバイザリがあるため、ビルド時に
  `COMPOSER_NO_SECURITY_BLOCKING=1` を設定しています。**検証用途専用**です。
- Laravel 10 は psr/http-message 2.x を使いますが、Octane 1.5.6 は 1.x が必要なので、`composer require -W` でダウングレードしています。
- 両方を有効にすると計装が二重になるため、オーバーヘッドの評価は片方ずつの計測と比べてください。
- 起動時の `artisan migrate` では、ノイズを避けるため OTel と NR の両方を無効にしています。
