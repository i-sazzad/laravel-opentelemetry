<?php

namespace Laratel\Opentelemetry\Middleware;

use Closure;
use Laratel\Opentelemetry\Helpers\Helper;
use Laratel\Opentelemetry\Services\TraceService;
use Illuminate\Http\Request;
use Throwable;

class OpenTelemetryTraceMiddleware
{
    private Helper $helper;

    public function __construct(Helper $helper)
    {
        $this->helper = $helper;
    }

    /**
     * @throws Throwable
     */
    public function handle(Request $request, Closure $next)
    {
        $trace = new TraceService();

        $tracer = $trace->getTracer();

        // Skip tracing if tracer is not available
        if (!$tracer) {
            return $next($request);
        }

        // Skip tracing for excluded paths
        if ($this->helper->shouldExclude($request->path())) {
            return $next($request);
        }

        $trace->dbQueryTrace(); // Set up DB query trace if necessary

        // Start a new span for the request
        $span = $tracer->spanBuilder($request->method() . ' ' . $request->path())->startSpan();
        $scope = $span->activate();
        $startTime = microtime(true);

        try {
            $response = $next($request);

            // Set span attributes and add custom events
            $trace->setSpanAttributes($span, $request, $response);
            $trace->addRouteEvents($span, $request, $response, $startTime);
        } catch (Throwable $e) {
            $trace->handleException($span, $e);
            $trace->setSpanAttributes($span, $request, null);
            $trace->addRouteEvents($span, $request, null, $startTime);

            throw $e;
        } finally {
            // Always ensure that the span is ended and detached
            $scope?->detach();
            $span?->end();
        }

        return $response;
    }
}
