<?php

namespace Laratel\Opentelemetry\Tests\Feature;

use Laratel\Opentelemetry\Middleware\OpenTelemetryMetricsMiddleware;
use Laratel\Opentelemetry\Middleware\OpenTelemetryTraceMiddleware;
use Laratel\Opentelemetry\Tests\TestCase;

class MiddlewareAliasTest extends TestCase
{
    public function test_middleware_aliases_are_registered(): void
    {
        $aliases = $this->app['router']->getMiddleware();

        $this->assertSame(OpenTelemetryTraceMiddleware::class, $aliases['opentelemetry.trace'] ?? null);
        $this->assertSame(OpenTelemetryMetricsMiddleware::class, $aliases['opentelemetry.metrics'] ?? null);
    }

    public function test_dead_config_keys_are_removed(): void
    {
        $config = require __DIR__.'/../../src/config/opentelemetry.php';

        foreach (['traces_exporter', 'logs_exporter', 'metrics_exporter', 'propagators', 'traces_sampler', 'network_states', 'resource_attributes'] as $key) {
            $this->assertArrayNotHasKey($key, $config);
        }
    }
}
