<?php

namespace Laratel\Opentelemetry\Tests\Feature;

use Laratel\Opentelemetry\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged(): void
    {
        $this->assertSame('laravel_app', config('opentelemetry.service_name'));
        $this->assertIsArray(config('opentelemetry.excluded_routes'));
    }

    public function test_tracer_is_not_bound_when_collector_unreachable(): void
    {
        $this->assertFalse($this->app->bound('tracer'));
        $this->assertFalse($this->app->bound('metrics'));
    }
}
