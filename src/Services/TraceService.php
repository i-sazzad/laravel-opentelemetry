<?php

namespace Laratel\Opentelemetry\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

class TraceService
{
    /**
     * The OpenTelemetry Tracer instance.
     * @var TracerInterface|null
     */
    private ?TracerInterface $tracer;

    /**
     * Flag to ensure the DB listener is only registered once.
     */
    private static bool $listenerRegistered = false;

    /**
     * Cache for the compiled exclusion regex pattern.
     */
    private static ?string $exclusionPattern = null;

    /**
     * TraceService constructor.
     * Resolves the tracer from the service container once.
     */
    public function __construct()
    {
        $this->tracer = app()->bound('tracer') ? app('tracer') : null;
    }

    /**
     * Returns the tracer instance.
     *
     * @return TracerInterface|null
     */
    public function getTracer(): ?TracerInterface
    {
        return $this->tracer;
    }

    /**
     * Registers a listener to trace database queries.
     * This method is now idempotent and safe to call multiple times.
     */
    public function dbQueryTrace(): void
    {
        // Exit if the tracer is not available or if the listener is already registered.
        if (! $this->tracer || self::$listenerRegistered) {
            return;
        }

        DB::listen(function (QueryExecuted $query) {
            if ($this->shouldExcludeQuery($query->sql)) {
                return;
            }

            // Record the query as a new span.
            $startTime = (int) (microtime(true) * 1_000_000_000) - (int) ($query->time * 1_000_000);

            $span = $this->tracer->spanBuilder('SQL Query')
                ->setStartTimestamp($startTime)
                ->setAttribute('db.system', $query->connection->getDriverName())
                ->setAttribute('db.name', $query->connection->getDatabaseName())
                ->setAttribute('db.statement', $query->sql)
                 ->setAttribute('db.bindings', json_encode($query->bindings))
                ->startSpan();

            $span->end($startTime + (int) ($query->time * 1_000_000));
        });

        self::$listenerRegistered = true;
    }

    /**
     * Adds standard HTTP and request attributes to a given span.
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
            'request.content_length' => (int) $request->header('Content-Length', 0),
            'response.content_length' => strlen($response->getContent() ?? ''),
            'response.time_ms' => (microtime(true) - LARAVEL_START) * 1000,
        ]);

        if ($request->user()) {
            $span->setAttribute('user.id', $request->user()->id);
        }
    }

    /**
     * Records an exception on a given span, marking it as an error.
     */
    public function handleException(SpanInterface $span, Throwable $exception): void
    {
        $span->recordException($exception);
        $span->setStatus('error', $exception->getMessage());
    }

    /**
     * Checks if a given SQL query should be excluded from tracing based on configuration.
     *
     * This method compiles and caches a regex pattern for performance.
     */
    private function shouldExcludeQuery(string $sql): bool
    {
        $excludedQueries = config('opentelemetry.excluded_queries');

        if (empty($excludedQueries)) {
            return false;
        }

        // Compile and cache the regex pattern for efficiency.
        if (self::$exclusionPattern === null) {
            $patterns = array_map('preg_quote', $excludedQueries, ['/']);
            self::$exclusionPattern = '/' . implode('|', $patterns) . '/i';
        }

        return (bool) preg_match(self::$exclusionPattern, $sql);
    }

    /**
     * Gets a descriptive name for the current route.
     */
    private function getRouteName(Request $request): string
    {
        $route = $request->route();

        if (! $route) {
            return 'unknown';
        }

        return $route->getName() ?? $route->uri();
    }
}
