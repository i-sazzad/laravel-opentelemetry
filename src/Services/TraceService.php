<?php

namespace Laratel\Opentelemetry\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class TraceService
{
    private static bool $listenerRegistered = false;

    public function getTracer(): ?object
    {
        $tracer = app('tracer');
        return $tracer ?: null;
    }

    public function dbQueryTrace(): void
    {
        $tracer = $this->getTracer();
        if (!$tracer) {
            return;
        }

        if (self::$listenerRegistered) {
            return;
        }

        self::$listenerRegistered = true;

        DB::listen(function ($query) use ($tracer) {
            if ($this->shouldExcludeQuery($query->sql)) {
                return;
            }

            $startTime = (int) (microtime(true) * 1_000_000_000);

            $span = $tracer->spanBuilder('SQL Query: ' . $query->sql)
                ->setAttribute('db.system', 'mysql')
                ->setAttribute('db.statement', $query->sql)
                ->setAttribute('db.bindings', json_encode($query->bindings))
                ->setStartTimestamp($startTime)
                ->startSpan();

            $span->end($startTime + (int) ($query->time * 1_000_000));
        });
    }

    public function setSpanAttributes($span, Request $request, $response): void
    {
        $span->setAttribute('http.method', $request->method());
        $span->setAttribute('http.url', $request->fullUrl());
        $span->setAttribute('http.route', $this->getRouteName($request));
        $span->setAttribute('http.status_code', $response->getStatusCode());
        $span->setAttribute('http.client_ip', $request->ip());
        $span->setAttribute('http.user_agent', $request->header('User-Agent', 'unknown'));
        $span->setAttribute('http.content_length', $request->header('Content-Length', 0));

        if ($request->user()) {
            $span->setAttribute('user.id', $request->user()->id);
        }

        $span->setAttribute('response.content_length', strlen($response->getContent() ?? ''));
        $span->setAttribute('response.time', microtime(true) - LARAVEL_START);

        $span->addEvent('http.request.received', [
            'method' => $request->method(),
            'route' => $request->path(),
            'status' => $response->getStatusCode(),
        ]);
    }

    public function addCustomEvents($span, Request $request, $response, $startTime): void
    {
        $span->addEvent('request.processed', [
            'processing_time_ms' => (microtime(true) - $startTime) * 1000,
            'method' => $request->method(),
            'route' => $request->path(),
            'status_code' => $response->getStatusCode(),
        ]);
    }

    public function handleException($span, Throwable $e): void
    {
        $span->setAttribute('error', true);
        $span->setAttribute('exception.type', get_class($e));
        $span->setAttribute('exception.message', $e->getMessage());
        $span->setAttribute('exception.stacktrace', $e->getTraceAsString());
        $span->addEvent('exception.raised', [
            'type' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }

    private function shouldExcludeQuery(string $sql): bool
    {
        $excludedQueries = config('opentelemetry.excluded_queries', []);

        foreach ($excludedQueries as $excludedQuery) {
            if (str_contains($sql, $excludedQuery)) {
                return true;
            }
        }

        return false;
    }

    private function getRouteName(Request $request): string
    {
        $route = $request->route();
        return $route ? $route->getName() ?? $route->uri() : 'unknown';
    }
}
