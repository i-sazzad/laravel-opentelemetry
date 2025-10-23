<?php

namespace Laratel\Opentelemetry\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

class TraceService
{
    private ?TracerInterface $tracer;
    private static bool $listenerRegistered = false;
    private static ?string $exclusionPattern = null;

    public function __construct()
    {
        $this->tracer = app()->bound('tracer') ? app('tracer') : null;
    }

    public function getTracer(): ?TracerInterface
    {
        return $this->tracer;
    }

    /**
     * Registers a listener to trace database queries (idempotent).
     */
    public function dbQueryTrace(): void
    {
        if (! $this->tracer || self::$listenerRegistered) {
            return;
        }

        DB::listen(function (QueryExecuted $query) {
            if ($this->shouldExcludeQuery($query->sql)) {
                return;
            }

            $clock = Clock::getDefault();
            $endTime = $clock->now();
            $startTime = $endTime - (int) ($query->time * 1_000_000);

            $span = $this->tracer->spanBuilder($query->sql)
                ->setStartTimestamp($startTime)
                ->setAttribute('db.system', $query->connection->getDriverName())
                ->setAttribute('db.name', $query->connection->getDatabaseName())
                ->setAttribute('db.statement', $query->sql)
                ->startSpan();

            $span->setStatus(StatusCode::STATUS_OK);
            $span->end($endTime);
        });

        self::$listenerRegistered = true;
    }

    /**
     * Set standard HTTP span attributes.
     */
    public function setSpanAttributes(SpanInterface $span, Request $request, $response): void
    {
        $span->setAttributes([
            'http.method' => $request->method(),
            'http.url' => $request->fullUrl(),
            'http.route' => $this->getRouteName($request),
            'http.status_code' => $response->getStatusCode(),
            'http.client_ip' => $request->ip(),
            'http.user_agent' => $request->header('User-Agent', 'unknown'),
            'http.request_content_length' => (int) $request->header('Content-Length', 0),
            'http.response_content_length' => strlen($response->getContent() ?? ''),
            'http.response_time_ms' => (microtime(true) - LARAVEL_START) * 1000,
        ]);

        if ($request->user()) {
            $span->setAttribute('enduser.id', $request->user()->id);
        }

        $span->setStatus(StatusCode::STATUS_OK);
    }

    /**
     * Record and mark an exception on the span.
     */
    public function handleException(SpanInterface $span, Throwable $exception): void
    {
        $span->recordException($exception, [
            'exception.type' => get_class($exception),
            'exception.message' => $exception->getMessage(),
        ]);
        $span->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
    }

    /**
     * Exclude certain queries from tracing (based on config).
     */
    private function shouldExcludeQuery(string $sql): bool
    {
        $excludedQueries = config('opentelemetry.excluded_queries');

        if (empty($excludedQueries)) {
            return false;
        }

        if (self::$exclusionPattern === null) {
            $patterns = array_map(fn($q) => preg_quote($q, '/'), $excludedQueries);
            self::$exclusionPattern = '/(' . implode('|', $patterns) . ')/i';
        }

        return (bool) preg_match(self::$exclusionPattern, $sql);
    }

    /**
     * Get route name or URI.
     */
    private function getRouteName(Request $request): string
    {
        $route = $request->route();
        return $route ? ($route->getName() ?? $route->uri()) : 'unknown';
    }
}
