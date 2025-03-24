<?php

namespace Laratel\Opentelemetry\Middleware;

use Closure;
use Laratel\Opentelemetry\Helpers\Helper;
use Laratel\Opentelemetry\Services\TraceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenTelemetryTraceMiddleware
{
    protected TraceService $trace;
    private Helper $helper;

    public function __construct(TraceService $trace, Helper $helper)
    {
        $this->trace = $trace;
        $this->helper = $helper;
    }

    /**
     * @throws Throwable
     */
    public function handle(Request $request, Closure $next)
    {
        // Skip tracing for excluded paths
        if ($this->helper->shouldExclude($request->path())) {
            return $next($request);
        }

        $tracer = $this->trace->getTracer();
        if (!$tracer) {
            // Skip tracing if tracer is not available
            return $next($request);
        }

        $this->trace->dbQueryTrace(); // Set up DB query trace if necessary

        // Start a new span for the request
        $span = $tracer->spanBuilder($request->method() . ' ' . $request->path())->startSpan();
        $scope = $span->activate();
        $startTime = microtime(true);

        try {
            // Process the request
            $response = $next($request);

            // Set span attributes and add custom events
            $this->trace->setSpanAttributes($span, $request, $response);
            $this->trace->addCustomEvents($span, $request, $response, $startTime);

            return $response;
        } catch (Throwable $e) {
            // Handle exception and record it in the span, but continue the request
            $this->trace->handleException($span ?? null, $e);
            Log::error('OpenTelemetry Trace Error', [
                'exception' => $e->getMessage(),
                'stack' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            return $next($request);  // Continue processing without interrupting the request flow
        } finally {
            // Always ensure that the span is ended and detached
            try {
                $scope?->detach();
                $span?->end();
            } catch (Throwable $flushError) {
                // Log any error related to ending the span but do not interrupt the request flow
                Log::error('OpenTelemetry Span End Error', [
                    'error' => $flushError->getMessage(),
                    'stack' => $flushError->getTraceAsString(),
                    'request' => $request->all()
                ]);
            }
        }
    }
}
