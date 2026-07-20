<?php

namespace Laratel\Opentelemetry\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

class TraceService
{
    private ?TracerInterface $tracer;

    private static bool $listenerRegistered = false;

    private ?string $exclusionPattern = null;

    public function __construct(?TracerInterface $tracer = null)
    {
        $this->tracer = $tracer ?? (app()->bound('tracer') ? app('tracer') : null);
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

            $operation = strtolower(strtok(ltrim($query->sql), " \t(")) ?: 'query';
            $span = $this->tracer->spanBuilder($operation)
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
     * Set HTTP span attributes following OTel semantic conventions.
     */
    public function setSpanAttributes(SpanInterface $span, Request $request, $response, float $startTime): void
    {
        $span->setAttributes([
            'http.request.method' => $request->method(),
            'url.full' => $request->fullUrl(),
            // Semconv http.route is the route TEMPLATE (matches the metrics
            // labels), never the route name or the raw path.
            'http.route' => $request->route()?->uri() ?? 'unknown',
            'http.response.status_code' => $response->getStatusCode(),
            'client.address' => $request->ip(),
            'user_agent.original' => $request->header('User-Agent', 'unknown'),
            'http.request.body.size' => (int) $request->header('Content-Length', 0),
            'http.response.body.size' => strlen($response->getContent() ?: ''),
            'http.server.duration_ms' => (microtime(true) - $startTime) * 1000,
        ]);

        if ($request->user()) {
            $span->setAttribute('enduser.id', (string) $request->user()->id);
        }

        // Ok is final per the OTel spec — setting it here for an error
        // response would make the middleware's later Error status a no-op.
        if ($response->getStatusCode() < 400) {
            $span->setStatus(StatusCode::STATUS_OK);
        }
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
     * Exclude certain queries from tracing. Config values are plain,
     * case-insensitive substrings (not regexes).
     */
    public function shouldExcludeQuery(string $sql): bool
    {
        $excludedQueries = config('opentelemetry.excluded_queries');

        if (empty($excludedQueries)) {
            return false;
        }

        if ($this->exclusionPattern === null) {
            $patterns = array_map(fn ($q) => preg_quote($q, '/'), $excludedQueries);
            $this->exclusionPattern = '/('.implode('|', $patterns).')/i';
        }

        return (bool) preg_match($this->exclusionPattern, $sql);
    }
}
