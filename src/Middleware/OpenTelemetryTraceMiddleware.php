<?php

namespace Laratel\Opentelemetry\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laratel\Opentelemetry\Helpers\Helper;
use Laratel\Opentelemetry\Services\TraceService;
use Throwable;

readonly class OpenTelemetryTraceMiddleware
{
    /**
     * Middleware constructor.
     *
     * Injects dependencies via the service container for better performance and testability.
     */
    public function __construct(
        private Helper $helper,
        private TraceService $traceService
    ) {
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     * @throws Throwable
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $tracer = $this->traceService->getTracer();

        // If the tracer isn't available or the path is excluded, skip tracing.
        if (! $tracer || $this->helper->shouldExclude($request->path())) {
            return $next($request);
        }

        // Ensure the database query listener is registered.
        $this->traceService->dbQueryTrace();

        // **FIXED LINE:** Check if the route exists before trying to access methods on it.
        $route = $request->route();
        $spanName = $request->method() . ' ' . ($route ? ($route->getName() ?? $route->uri()) : $request->path());

        $span = $tracer->spanBuilder($spanName)->startSpan();
        $scope = $span->activate();

        try {
            $response = $next($request);

            // This is the "happy path". We have a successful response.
            $this->traceService->setSpanAttributes($span, $request, $response);

            return $response;
        } catch (Throwable $e) {
            // The request resulted in an exception.
            $this->traceService->handleException($span, $e);

            // Re-throw the exception to ensure Laravel's error handling pipeline continues.
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
