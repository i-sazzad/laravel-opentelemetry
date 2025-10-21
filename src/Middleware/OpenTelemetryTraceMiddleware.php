<?php

namespace Laratel\Opentelemetry\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laratel\Opentelemetry\Helpers\Helper;
use Laratel\Opentelemetry\Services\TraceService;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

readonly class OpenTelemetryTraceMiddleware
{
    public function __construct(
        private Helper $helper,
        private TraceService $traceService
    ) {
    }

    /**
     * Handle an incoming request and create a root trace span.
     *
     * @throws Throwable
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $tracer = $this->traceService->getTracer();

        // Skip if tracer not available or excluded route
        if (! $tracer || $this->helper->shouldExclude($request->path())) {
            return $next($request);
        }

        // Ensure DB query tracing is active
        $this->traceService->dbQueryTrace();

        $route = $request->route();
        $spanName = $request->method() . ' ' . ($route ? ($route->getName() ?? $route->uri()) : $request->path());

        $span = $tracer
            ->spanBuilder($spanName)
            ->setStartTimestamp((int) (LARAVEL_START * 1_000_000_000))
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $scope = $span->activate();

        try {
            $response = $next($request);

            // Add standard HTTP attributes
            $this->traceService->setSpanAttributes($span, $request, $response);

            // Record HTTP error status if response failed
            $status = $response->getStatusCode();
            if ($status >= 400) {
                $span->setStatus(StatusCode::STATUS_ERROR, "HTTP $status");
            }

            // Include trace identifiers in the response (optional but useful)
            $context = $span->getContext();
            $response->headers->set('trace-id', $context->getTraceId());
            $response->headers->set('span-id', $context->getSpanId());

            // Route-level info
            if ($route) {
                $span->setAttributes([
                    'http.route.name' => $route->getName(),
                    'http.route.uri' => $route->uri(),
                    'controller.action' => $route->getActionName(),
                ]);
            }

            return $response;
        } catch (Throwable $e) {
            // Record exception
            $this->traceService->handleException($span, $e);
            throw $e;
        } finally {
            // Close and detach span
            $scope->detach();
            $span->end();
        }
    }
}
