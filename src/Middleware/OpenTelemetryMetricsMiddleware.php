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
    protected Helper $helper;
    protected float $startTime;
    protected static bool $cacheWrapped = false;
    private static bool $dbListenerRegistered = false;

    public function __construct(Helper $helper)
    {
        $this->helper = $helper;
    }

    /**
     * Handle incoming request and record observability metrics.
     *
     * @throws Throwable
     */
    public function handle(Request $request, Closure $next): Response
    {
        $metrics = new MetricsService();

        // Skip if OpenTelemetry not initialized
        if (!$metrics->metrics) {
            return $next($request);
        }

        // Skip excluded routes (like /health, /metrics itself)
        if ($this->helper->shouldExclude($request->path())) {
            return $next($request);
        }

        // Prevent duplicate recording
        if ($request->attributes->get('metrics_recorded', false)) {
            return $next($request);
        }
        $request->attributes->set('metrics_recorded', true);

        // Wrap Cache operations once per app lifecycle
        if (!self::$cacheWrapped) {
            $metrics->wrapCacheOperations();
            self::$cacheWrapped = true;
        }

        // Start timer and register DB query listener
        $this->startTime = microtime(true);
        if (!self::$dbListenerRegistered) {
            DB::listen(fn($query) => $metrics->recordDbMetrics($query));
            self::$dbListenerRegistered = true;
        }

        try {
            // Process the request
            $response = $next($request);

            // Record HTTP + system + network metrics
            $metrics->recordHttpMetrics($request, $response, $this->startTime);
            $metrics->recordSystemMetrics();
            $metrics->recordNetworkMetrics();
        } catch (Throwable $e) {
            // Capture any thrown exceptions
            $metrics->recordErrorMetrics($e);
            throw $e;
        } finally {
            // Flush metrics safely (OTLP/Prometheus exporter)
            if (method_exists($this->helper, 'flushMetrics')) {
                $this->helper->flushMetrics();
            }
        }

        return $response;
    }
}
