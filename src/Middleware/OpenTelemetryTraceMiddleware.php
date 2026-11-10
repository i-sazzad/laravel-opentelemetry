<?php

namespace Laratel\Opentelemetry\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laratel\Opentelemetry\Helpers\Helper;
use Laratel\Opentelemetry\Services\TraceService;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use Throwable;

readonly class OpenTelemetryTraceMiddleware
{
    public function __construct(
        private Helper $helper,
        private TraceService $traceService
    ) {}

    /**
     * Handle an incoming request and create a root trace span.
     *
     * @throws Throwable
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $tracer = $this->traceService->getTracer();

        if (! $tracer || $this->helper->shouldExclude($request->path())) {
            return $next($request);
        }

        // Applied both globally and on a route: keep the outer span only.
        if ($request->attributes->get('otel_traced', false)) {
            return $next($request);
        }
        $request->attributes->set('otel_traced', true);

        $this->traceService->dbQueryTrace();

        // Continue a distributed trace if the caller sent W3C trace context.
        $parent = TraceContextPropagator::getInstance()->extract($request->headers->all());

        // Per-request start time. LARAVEL_START is unsuitable: under Octane
        // it is defined once, when the worker boots.
        $requestStart = $request->server('REQUEST_TIME_FLOAT');
        $startTime = is_numeric($requestStart) && $requestStart > 0 ? (float) $requestStart : microtime(true);

        // Until the route is matched the only low-cardinality name is the
        // method (the raw path would create one span name per URL).
        $span = $tracer
            ->spanBuilder($request->method())
            ->setParent($parent)
            ->setStartTimestamp((int) ($startTime * 1_000_000_000))
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $scope = $span->activate();

        try {
            $response = $next($request);

            // Routing happens inside $next for global middleware; rename the
            // span to the low-cardinality route template now that it's known.
            $route = $request->route();
            if ($route) {
                $span->updateName($request->method().' /'.ltrim($route->uri(), '/'));
                $span->setAttributes([
                    'laravel.route.name' => $route->getName(),
                    'laravel.controller.action' => $route->getActionName(),
                ]);
            }

            $this->traceService->setSpanAttributes($span, $request, $response, $startTime);

            // Laravel converts exceptions into responses before they reach
            // middleware; the original is kept on the response.
            $exception = $response->exception ?? null;
            if ($exception instanceof Throwable) {
                $span->recordException($exception);
            }

            $status = $response->getStatusCode();
            if ($status >= 400) {
                $span->setStatus(StatusCode::STATUS_ERROR, "HTTP $status");
            }

            $context = $span->getContext();
            $response->headers->set('trace-id', $context->getTraceId());
            $response->headers->set('span-id', $context->getSpanId());

            return $response;
        } catch (Throwable $e) {
            $this->traceService->handleException($span, $e);
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
