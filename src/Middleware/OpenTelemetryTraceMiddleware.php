<?php

namespace Laratel\Opentelemetry\Middleware;

use Closure;
use Laratel\Opentelemetry\Helpers\Helper;
use Laratel\Opentelemetry\Services\TraceService;
use Illuminate\Http\Request;
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
        if ($this->helper->shouldExclude($request->path())) {
            return $next($request);
        }

        $tracer = $this->trace->getTracer();
        if (!$tracer) {
            return $next($request); // Skip tracing if tracer is not available
        }

        $this->trace->dbQueryTrace();

        $span = $tracer->spanBuilder($request->method() . ' ' . $request->path())->startSpan();
        $scope = $span->activate();
        $startTime = microtime(true);

        try {
            $response = $next($request);

            $this->trace->setSpanAttributes($span, $request, $response);
            $this->trace->addCustomEvents($span, $request, $response, $startTime);

            return $response;
        } catch (Throwable $e) {
            $this->trace->handleException($span, $e);
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

}
