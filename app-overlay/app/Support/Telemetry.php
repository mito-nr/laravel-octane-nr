<?php

namespace App\Support;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\Context\Context;
use Throwable;

/**
 * Small helper that emits the same logical operation to
 *  - OpenTelemetry (manual spans, parent restored from a W3C carrier)
 *  - New Relic PHP agent (custom transaction + distributed tracing headers)
 *
 * Octane::concurrently() runs closures in Swoole *task workers* (other processes),
 * so trace context has to be passed explicitly to keep a single distributed trace.
 */
class Telemetry
{
    /** Context to ship to a task worker. */
    public static function propagation(): array
    {
        $otel = [];
        TraceContextPropagator::getInstance()->inject($otel);

        $nr = [];
        if (function_exists('newrelic_insert_distributed_trace_headers')) {
            newrelic_insert_distributed_trace_headers($nr);
        }

        return ['otel' => $otel, 'nr' => $nr];
    }

    /**
     * Octane workers never exit, and PHP's batch processor has no background timer,
     * so spans are pushed out explicitly (one OTLP request per HTTP request / task).
     */
    public static function flush(bool $metrics = true): void
    {
        $providers = [Globals::tracerProvider()];
        if ($metrics) {
            // the PHP metric reader only exports on collect/forceFlush (no timer either)
            $providers[] = Globals::meterProvider();
        }
        foreach ($providers as $provider) {
            if (method_exists($provider, 'forceFlush')) {
                $provider->forceFlush();
            }
        }
    }

    public static function currentTraceId(): string
    {
        return \OpenTelemetry\API\Trace\Span::getCurrent()->getContext()->getTraceId();
    }

    /** OpenTelemetry span around $fn. */
    public static function span(string $name, callable $fn, array $attributes = [], ?array $otelCarrier = null)
    {
        $tracer = Globals::tracerProvider()->getTracer('app.demo');
        $parent = $otelCarrier ? TraceContextPropagator::getInstance()->extract($otelCarrier) : Context::getCurrent();

        $span = $tracer->spanBuilder($name)
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_INTERNAL)
            ->setAttributes($attributes)
            ->startSpan();
        $scope = $span->storeInContext($parent)->activate();

        try {
            return $fn();
        } catch (Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    /**
     * Entry point used inside a task worker: wraps $fn in an OTel span (child of the
     * request span) and in a New Relic transaction linked through distributed tracing.
     */
    public static function inTask(string $name, array $propagation, callable $fn, array $attributes = [])
    {
        $nrActive = function_exists('newrelic_start_transaction');
        if ($nrActive) {
            // drop the process-wide implicit CLI transaction, start one per task
            newrelic_end_transaction(true);
            newrelic_start_transaction((string) ini_get('newrelic.appname'));
            newrelic_name_transaction('Task/'.$name);
            if (! empty($propagation['nr'])) {
                // The closure reaches the task worker through laravel/serializable-closure,
                // and the unserialized array values are PHP references. The agent rejects
                // them ("Invalid array value, expected a string"), so copy into plain strings.
                $headers = [];
                foreach ($propagation['nr'] as $k => $v) {
                    $headers[(string) $k] = (string) $v;
                }
                newrelic_accept_distributed_trace_headers($headers, 'Other');
            }
        }

        try {
            return self::span($name, $fn, $attributes + ['worker.pid' => getmypid()], $propagation['otel'] ?? null);
        } catch (Throwable $e) {
            if ($nrActive) {
                newrelic_notice_error($e->getMessage(), $e);
            }
            throw $e;
        } finally {
            if ($nrActive) {
                newrelic_end_transaction();
            }
            // Task workers have no "after response" hook, so flush here. This is a
            // synchronous OTLP call and counts toward the task's duration, which is why
            // the app exports to the local collector instead of directly to New Relic.
            self::flush(metrics: false); // tasks record no metrics
        }
    }
}
