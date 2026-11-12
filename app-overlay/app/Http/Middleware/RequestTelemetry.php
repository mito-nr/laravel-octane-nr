<?php

namespace App\Http\Middleware;

use App\Support\Telemetry;
use Closure;
use Illuminate\Http\Request;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

/**
 * One request = one New Relic transaction AND one OpenTelemetry SERVER span
 * AND one `http.server.request.duration` measurement.
 *
 * - The New Relic PHP agent assumes "1 process = 1 transaction" (php-fpm / CLI).
 *   Under Octane a worker serves many requests, so transactions are cut explicitly.
 * - OTel's Laravel auto-instrumentation hooks don't produce a server span under
 *   Octane's long-running CLI worker, so the root span is created here as well.
 * - New Relic's OTel APM UI (Summary / Transactions) is driven by
 *   `apm.service.transaction.duration`, which NR synthesizes from the
 *   `http.server.request.duration` metric. Without that metric the UI can only
 *   fall back to span-derived (sampled, "legacy") data.
 */
class RequestTelemetry
{
    /** Bucket boundaries recommended by the HTTP semantic conventions (seconds). */
    private const DURATION_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10];

    /** Created once per worker; the meter provider lives as long as the Octane worker. */
    private static ?HistogramInterface $duration = null;

    public function handle(Request $request, Closure $next)
    {
        $start = hrtime(true);
        $httpRoute = '/'.ltrim($request->route()?->uri() ?? $request->path(), '/');
        $route = $request->method().' '.$httpRoute;
        $headers = array_map(fn ($v) => $v[0] ?? '', $request->headers->all());
        $status = 500;
        $errorType = null;

        // ---- New Relic ----
        $nr = function_exists('newrelic_start_transaction');
        if ($nr) {
            newrelic_end_transaction(true); // discard previous / implicit transaction
            newrelic_start_transaction((string) ini_get('newrelic.appname'));
            // sapi=cli makes every transaction a background job; mark it as a web transaction
            newrelic_background_job(false);
            newrelic_name_transaction($route);
            newrelic_add_custom_parameter('worker.pid', getmypid());
            if (isset($headers['traceparent']) || isset($headers['newrelic'])) {
                newrelic_accept_distributed_trace_headers($headers, 'HTTP');
            }
        }

        // ---- OpenTelemetry ----
        $parent = Globals::propagator()->extract($headers);
        $span = Globals::tracerProvider()->getTracer('app.http')
            ->spanBuilder($route)
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('http.request.method', $request->method())
            ->setAttribute('http.route', $httpRoute)
            ->setAttribute('url.full', $request->fullUrl())
            ->setAttribute('url.scheme', $request->getScheme())
            ->setAttribute('worker.pid', getmypid())
            ->startSpan();
        $scope = $span->storeInContext($parent)->activate();

        try {
            $response = $next($request);
            $status = $response->getStatusCode();
            $span->setAttribute('http.response.status_code', $status);
            // Laravel's routing pipeline renders exceptions into a response before they reach us
            $e = $response->exception ?? null;
            if ($e instanceof Throwable) {
                $span->recordException($e);
                $errorType = get_class($e);
                if ($nr) {
                    newrelic_notice_error($e->getMessage(), $e);
                }
            }
            if ($status >= 500) {
                $errorType ??= (string) $status;
                $span->setAttribute('error.type', $errorType);
                $span->setStatus(StatusCode::STATUS_ERROR, $e?->getMessage());
            }
            if ($nr) {
                newrelic_add_custom_parameter('http.status', $status);
            }

            return $response;
        } catch (Throwable $e) {
            $errorType = get_class($e);
            $span->setAttribute('error.type', $errorType);
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            if ($nr) {
                newrelic_notice_error($e->getMessage(), $e);
            }
            throw $e;
        } finally {
            $scope->detach();
            $span->end();

            // attributes follow the stable HTTP semconv; NR derives the transaction
            // name "WebTransaction/server/{method} {http.route}" from them
            $attributes = [
                'http.request.method' => $request->method(),
                'http.route' => $httpRoute,
                'http.response.status_code' => $status,
                'url.scheme' => $request->getScheme(),
            ];
            if ($errorType !== null) {
                $attributes['error.type'] = $errorType;
            }
            self::durationHistogram()->record((hrtime(true) - $start) / 1e9, $attributes);

            if ($nr) {
                newrelic_end_transaction();
            }
        }
    }

    /** Called by Octane after the response has been sent to the client. */
    public function terminate(Request $request, $response): void
    {
        Telemetry::flush();
    }

    private static function durationHistogram(): HistogramInterface
    {
        return self::$duration ??= Globals::meterProvider()
            ->getMeter('app.http')
            ->createHistogram(
                'http.server.request.duration',
                's',
                'Duration of HTTP server requests.',
                ['ExplicitBucketBoundaries' => self::DURATION_BUCKETS],
            );
    }
}
