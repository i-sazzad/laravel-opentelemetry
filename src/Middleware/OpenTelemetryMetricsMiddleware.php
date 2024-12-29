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
        $this->startTime = microtime(true);

        // Skip recording metrics if the route is excluded
        if ($this->helper->shouldExclude($request->path())) {
            return $next($request);
        }

        try {
            // Start recording database and cache metrics
            DB::listen(function ($query) {
                $this->metrics->recordDbMetrics($query);
            });
            $this->metrics->wrapCacheOperations();

            // Process the request and capture the response
            $response = $next($request);

            // Record HTTP and system metrics
            $this->metrics->recordMetrics($request, $response, $this->startTime);
            $this->metrics->recordSystemMetrics();
            $this->metrics->recordNetworkMetrics();

            return $response;
        } catch (Throwable $e) {
            $this->metrics->recordErrorMetrics($e);
            throw $e;
        } finally {
            // Flush metrics data to the collector
            $this->helper->flushMetrics();
        }
    }
}
