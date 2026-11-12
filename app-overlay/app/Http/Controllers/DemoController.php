<?php

namespace App\Http\Controllers;

use App\Support\Telemetry;
use App\Support\Workloads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Octane\Facades\Octane;

class DemoController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'app' => 'laravel-octane-nr',
            'php' => PHP_VERSION,
            'swoole' => phpversion('swoole'),
            'newrelic_ext' => extension_loaded('newrelic') ? phpversion('newrelic') : false,
            'opentelemetry_ext' => extension_loaded('opentelemetry') ? phpversion('opentelemetry') : false,
            'worker_pid' => getmypid(),
            'endpoints' => ['/api/sequential?n=2', '/api/parallel?n=2', '/api/error'],
        ]);
    }

    /** DB + Redis one after another (baseline). */
    public function sequential(Request $request): JsonResponse
    {
        $n = $this->n($request);
        $start = microtime(true);

        $results = Telemetry::span('demo.sequential', function () use ($n) {
            $r = [];
            for ($i = 0; $i < $n; $i++) {
                $r[] = Telemetry::span('task.db', fn () => Workloads::db($i), ['i' => $i]);
                $r[] = Telemetry::span('task.redis', fn () => Workloads::redis($i), ['i' => $i]);
            }

            return $r;
        }, ['n' => $n]);

        return response()->json($this->payload('sequential', $start, $results));
    }

    /** DB + Redis fan-out to Swoole task workers via Octane::concurrently(). */
    public function parallel(Request $request): JsonResponse
    {
        $n = $this->n($request);
        $start = microtime(true);

        $results = Telemetry::span('demo.parallel', function () use ($n) {
            $ctx = Telemetry::propagation(); // taken inside the span => tasks become its children

            $tasks = [];
            for ($i = 0; $i < $n; $i++) {
                $tasks["db_{$i}"] = fn () => Telemetry::inTask('task.db', $ctx, fn () => Workloads::db($i), ['i' => $i]);
                $tasks["redis_{$i}"] = fn () => Telemetry::inTask('task.redis', $ctx, fn () => Workloads::redis($i), ['i' => $i]);
            }

            return array_values(Octane::concurrently($tasks, 5000));
        }, ['n' => $n]);

        return response()->json($this->payload('parallel', $start, $results));
    }

    /** Produces an exception to check error reporting on both sides. */
    public function error(): never
    {
        throw new \RuntimeException('Intentional demo error');
    }

    private function n(Request $request): int
    {
        return max(1, min(8, (int) $request->query('n', 2)));
    }

    private function payload(string $mode, float $start, array $results): array
    {
        return [
            'mode' => $mode,
            'total_ms' => round((microtime(true) - $start) * 1000, 1),
            'request_pid' => getmypid(),
            'otel_trace_id' => Telemetry::currentTraceId(),
            'results' => $results,
        ];
    }
}
