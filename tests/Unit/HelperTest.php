<?php

namespace Laratel\Opentelemetry\Tests\Unit;

use Laratel\Opentelemetry\Helpers\Helper;
use Laratel\Opentelemetry\Tests\TestCase;
use OpenTelemetry\API\Metrics\Noop\NoopMeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProvider;

class HelperTest extends TestCase
{
    public function test_flush_returns_false_when_not_bound(): void
    {
        $this->assertFalse((new Helper)->flushMetrics());
    }

    public function test_flush_does_not_crash_on_noop_provider(): void
    {
        // Simulate the catch-path fallback: a Noop bound under the SDK key.
        $this->app->instance(MeterProvider::class, new NoopMeterProvider);

        $this->assertFalse((new Helper)->flushMetrics());
    }

    public function test_should_exclude_matches_wildcards(): void
    {
        config()->set('opentelemetry.excluded_routes', ['assets/*', 'healthz']);

        $helper = new Helper;
        $this->assertTrue($helper->shouldExclude('assets/app.css'));
        $this->assertTrue($helper->shouldExclude('healthz'));
        $this->assertFalse($helper->shouldExclude('api/users'));
    }
}
