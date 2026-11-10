<?php

namespace Laratel\Opentelemetry\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Laratel\Opentelemetry\Support\Sql;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

class TraceService
{
    private ?TracerInterface $tracer;

    /** The event dispatcher the query listener was registered on. */
    private static ?\WeakReference $listeningOn = null;

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
     * Registers a listener to trace database queries. Idempotent per event
     * dispatcher, and the listener resolves this service from the container
     * on every query, so it keeps working when the container hands out a
     * fresh instance (Octane, tests).
     */
    public function dbQueryTrace(): void
    {
        if (! $this->tracer) {
            return;
        }

        $events = app('events');
        if (self::$listeningOn?->get() === $events) {
            return;
        }
        self::$listeningOn = \WeakReference::create($events);

        $events->listen(QueryExecuted::class, static function (QueryExecuted $query): void {
            app(self::class)->recordQuerySpan($query);
        });
    }

    /**
     * Emit a client span for an executed query, as a child of the active
     * span. Queries that run outside a traced request (excluded routes,
     * console, queue) are skipped instead of becoming orphan root spans.
     */
    public function recordQuerySpan(QueryExecuted $query): void
    {
        if (! $this->tracer || ! Span::getCurrent()->getContext()->isValid()) {
            return;
        }

        if ($this->shouldExcludeQuery($query->sql)) {
            return;
        }

        try {
            $endTime = Clock::getDefault()->now();
            $startTime = $endTime - (int) ($query->time * 1_000_000);

            $span = $this->tracer->spanBuilder(Sql::operation($query->sql))
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setStartTimestamp($startTime)
                ->setAttribute('db.system', $query->connection->getDriverName())
                ->setAttribute('db.name', $query->connection->getDatabaseName())
                ->setAttribute('db.statement', $query->sql)
                ->startSpan();

            $span->setStatus(StatusCode::STATUS_OK);
            $span->end($endTime);
        } catch (Throwable) {
            // Tracing must never break the query that was just executed.
        }
    }

    /**
     * Set HTTP span attributes following OTel semantic conventions.
     */
    public function setSpanAttributes(SpanInterface $span, Request $request, $response, float $startTime): void
    {
        $status = $response->getStatusCode();

        $span->setAttributes([
            'http.request.method' => $request->method(),
            'url.full' => $request->fullUrl(),
            // Semconv http.route is the route TEMPLATE (matches the metrics
            // labels), never the route name or the raw path.
            'http.route' => $request->route()?->uri() ?? 'unknown',
            'http.response.status_code' => $status,
            'client.address' => $request->ip(),
            'user_agent.original' => $request->header('User-Agent', 'unknown'),
            'http.request.body.size' => (int) $request->header('Content-Length', '0'),
            // Streamed and file responses have no buffered content.
            'http.response.body.size' => strlen($response->getContent() ?: ''),
            'http.server.duration_ms' => (microtime(true) - $startTime) * 1000,
        ]);

        try {
            $userId = $request->user()?->getAuthIdentifier();
            if ($userId !== null) {
                $span->setAttribute('enduser.id', (string) $userId);
            }
        } catch (Throwable) {
            // A misconfigured guard must not fail the request because of tracing.
        }

        // Ok is final per the OTel spec — setting it here for an error
        // response would make the middleware's later Error status a no-op.
        if ($status < 400) {
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
        $excludedQueries = array_filter(
            (array) config('opentelemetry.excluded_queries', []),
            // An empty pattern would match (and silently drop) every query.
            fn ($query) => is_string($query) && $query !== ''
        );

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
