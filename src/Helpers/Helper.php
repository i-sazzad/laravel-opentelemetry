<?php

namespace Laratel\Opentelemetry\Helpers;

use Illuminate\Support\Str;
use OpenTelemetry\SDK\Metrics\MeterProvider;

class Helper
{
    /**
     * Determine if a given path should be excluded from tracing.
     *
     * @param string $path The request path to check.
     * @return bool
     */
    public function shouldExclude(string $path): bool
    {
        $excludedPatterns = config('opentelemetry.excluded_routes', []);

        if (empty($excludedPatterns)) {
            return false;
        }

        foreach ($excludedPatterns as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Manually trigger a flush of any buffered metrics.
     *
     * This method is safe to call even if OpenTelemetry is disabled.
     * @return bool Returns true on success, false on failure.
     */
    public function flushMetrics(): bool
    {
        if (! app()->bound(MeterProvider::class)) {
            return false;
        }

        $meterProvider = app(MeterProvider::class);

        // The forceFlush method returns true on success.
        return $meterProvider->forceFlush();
    }
}
