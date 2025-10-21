<?php

namespace Laratel\Opentelemetry\Helpers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use OpenTelemetry\SDK\Metrics\MeterProvider;

class Helper
{
    /**
     * Determine if a given path should be excluded from tracing or metrics.
     */
    public function shouldExclude(string $path): bool
    {
        $excludedPatterns = config('opentelemetry.excluded_routes', []);

        foreach ($excludedPatterns as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Force flush of any buffered metrics safely.
     */
    public function flushMetrics(): bool
    {
        if (! app()->bound(MeterProvider::class)) {
            return false;
        }

        $meterProvider = app(MeterProvider::class);

        try {
            $result = $meterProvider->forceFlush();
            return $result === null ? true : (bool) $result;
        } catch (\Throwable $e) {
            if (config('app.debug')) {
                Log::warning('[OTEL] Metric flush failed: ' . $e->getMessage());
            }
            return false;
        }
    }
}
