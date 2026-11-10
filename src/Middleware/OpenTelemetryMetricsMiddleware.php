<?php

namespace Laratel\Opentelemetry\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laratel\Opentelemetry\Helpers\Helper;
use Laratel\Opentelemetry\Services\MetricsService;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class OpenTelemetryMetricsMiddleware
{
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

        $this->metrics->registerListeners();

        $startTime = microtime(true);
        $this->metrics->metrics['http_requests_in_progress']->add(1);

        try {
            $response = $next($request);

            $this->metrics->recordHttpMetrics($request, $response, $startTime);

            // Laravel converts exceptions into responses before they reach
            // middleware; the original is kept on the response.
            $exception = $response->exception ?? null;
            if ($exception instanceof Throwable) {
                $this->metrics->recordErrorMetrics($exception);
            }

            return $response;
        } catch (Throwable $e) {
            $this->metrics->recordErrorMetrics($e);
            throw $e;
        } finally {
            $this->metrics->metrics['http_requests_in_progress']->add(-1);
            $this->metrics->recordHostMetrics();
            // Export happens when the application terminates (after the
            // response is sent) — see OpenTelemetryServiceProvider::boot().
        }
    }
}
