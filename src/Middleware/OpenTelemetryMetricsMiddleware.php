<?php

namespace Laratel\Opentelemetry\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laratel\Opentelemetry\Helpers\Helper;
use Laratel\Opentelemetry\Services\MetricsService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class OpenTelemetryMetricsMiddleware
{
    private static bool $dbListenerRegistered = false;

    private static bool $cacheListenersRegistered = false;

    public function __construct(
        protected Helper $helper,
        protected MetricsService $metrics
    ) {}

    /**
     * @throws Throwable
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->metrics->metrics === [] || $this->helper->shouldExclude($request->path())) {
            return $next($request);
        }

        if ($request->attributes->get('metrics_recorded', false)) {
            return $next($request);
        }
        $request->attributes->set('metrics_recorded', true);

        if (! self::$cacheListenersRegistered) {
            $this->metrics->registerCacheEventListeners();
            self::$cacheListenersRegistered = true;
        }

        if (! self::$dbListenerRegistered) {
            DB::listen(fn ($query) => $this->metrics->recordDbMetrics($query));
            self::$dbListenerRegistered = true;
        }

        $startTime = microtime(true);
        $this->metrics->metrics['http_requests_in_progress']->add(1);

        try {
            $response = $next($request);

            $this->metrics->recordHttpMetrics($request, $response, $startTime);
            $this->metrics->recordSystemMetrics();
            $this->metrics->recordNetworkMetrics();

            return $response;
        } catch (Throwable $e) {
            $this->metrics->recordErrorMetrics($e);
            throw $e;
        } finally {
            $this->metrics->metrics['http_requests_in_progress']->add(-1);
            $this->helper->flushMetrics();
        }
    }
}
