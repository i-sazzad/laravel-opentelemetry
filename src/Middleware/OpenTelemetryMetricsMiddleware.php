<?php

namespace Laratel\Opentelemetry\Middleware;

use Closure;
use Laratel\Opentelemetry\Services\MetricsService;
use Laratel\Opentelemetry\Helpers\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class OpenTelemetryMetricsMiddleware
{
    private Helper $helper;
    protected float $startTime;

    public function __construct(Helper $helper)
    {
        $this->helper = $helper;
    }

    /**
     * Handles an incoming request and records metrics.
     *
     * @throws Throwable
     */
    public function handle(Request $request, Closure $next): Response
    {
        $metrics = new MetricsService();

        if (!$metrics->metrics) {
            // Skip recording if metrics are not available
            return $next($request);
        }

        // Skip recording metrics if the route is excluded
        if ($this->helper->shouldExclude($request->path())) {
            return $next($request);
        }

        // Skip if metrics are already recorded for this request
        if ($request->attributes->get('metrics_recorded', false)) {
            return $next($request);
        }

        // Mark metrics as recorded for this request
        $request->attributes->set('metrics_recorded', true);

        // Start the timer to record the duration of the request processing
        $this->startTime = microtime(true);

        try {
            // Start recording database and cache metrics
            DB::listen(function ($query) use ($metrics) {
                $metrics->recordDbMetrics($query); // Record database query metrics
            });

            $metrics->wrapCacheOperations(); // Record cache operations

            // Process the request and capture the response
            $response = $next($request);

            // Record HTTP and system metrics
            $metrics->recordMetrics($request, $response, $this->startTime);
            $metrics->recordSystemMetrics(); // Record system-level metrics
            $metrics->recordNetworkMetrics(); // Record network-level metrics

            return $response;
        } catch (Throwable $e) {
            // Record error metrics if something goes wrong
            $metrics->recordErrorMetrics($e);

            return $next($request); // Continue processing without failing the request
        } finally {
            // Flush metrics data to the collector, only if metrics were successfully captured
            $this->helper->flushMetrics();
        }
    }
}
