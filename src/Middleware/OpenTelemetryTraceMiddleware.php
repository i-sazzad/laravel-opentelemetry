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
        if (!$tracer) {
            // Skip tracing if tracer is not available
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
            // Process the request
            $response = $next($request);

            // Set span attributes and add custom events
            $trace->setSpanAttributes($span, $request, $response);
            $trace->addCustomEvents($span, $request, $response, $startTime);

            return $response;
        } catch (Throwable $e) {
            // Handle exception and record it in the span, but continue the request
            $trace->handleException($span ?? null, $e);
            return $next($request);  // Continue processing without interrupting the request flow
        } finally {
            // Always ensure that the span is ended and detached
            $scope?->detach();
            $span?->end();
        }
    }
}
