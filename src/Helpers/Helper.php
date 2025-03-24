<?php

namespace Laratel\Opentelemetry\Helpers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class Helper
{
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

    public function flushMetrics(): void
    {
        $meterProvider = app('meterProvider');
        $meterProvider->forceFlush();
    }
}
