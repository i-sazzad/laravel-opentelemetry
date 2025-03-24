<?php

namespace Laratel\Opentelemetry\Middleware;

use Closure;
use Laratel\Opentelemetry\Services\MetricsService;
use Laratel\Opentelemetry\Helpers\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class OpenTelemetryMetricsMiddleware
{
    protected MetricsService $metrics;
    private Helper $helper;
    protected float $startTime;

    public function __construct(MetricsService $metrics, Helper $helper)
    {
        $this->metrics = $metrics;
        $this->helper = $helper;
    }

    /**
     * Handles an incoming request and records metrics.
     *
     * @throws Throwable
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip if metrics are already recorded for this request
        if ($request->attributes->get('metrics_recorded', false)) {
            return $next($request);
        }

        // Mark metrics as recorded for this request
        $request->attributes->set('metrics_recorded', true);

        // Start the timer to record the duration of the request processing
        $this->startTime = microtime(true);

        // Skip recording metrics if the route is excluded
        if ($this->helper->shouldExclude($request->path())) {
            return $next($request);
        }

        try {
            // Start recording database and cache metrics
            DB::listen(function ($query) {
                $this->metrics->recordDbMetrics($query); // Record database query metrics
            });

            $this->metrics->wrapCacheOperations(); // Record cache operations

            // Process the request and capture the response
            $response = $next($request);

            // Record HTTP and system metrics
            $this->metrics->recordMetrics($request, $response, $this->startTime);
            $this->metrics->recordSystemMetrics(); // Record system-level metrics
            $this->metrics->recordNetworkMetrics(); // Record network-level metrics

            return $response;
        } catch (Throwable $e) {
            // Record error metrics if something goes wrong
            $this->metrics->recordErrorMetrics($e);

            // Log the error, continue processing the request without affecting the flow
            Log::error('OpenTelemetry Metrics Recording Error: ' . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'stack' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return $next($request); // Continue processing without failing the request
        } finally {
            // Flush metrics data to the collector, only if metrics were successfully captured
            try {
                $this->helper->flushMetrics();
            } catch (Throwable $flushError) {
                // Log the flush error but don't interrupt the request flow
                Log::error('OpenTelemetry Metrics Flush Error: ' . $flushError->getMessage(), [
                    'exception' => $flushError->getMessage(),
                    'stack' => $flushError->getTraceAsString(),
                    'request' => $request->all()
                ]);
            }
        }
    }
}
