<?php

namespace Laratel\Opentelemetry\Tests\Feature;

use Laratel\Opentelemetry\Tests\TestCase;

class ReachabilityCheckTest extends TestCase
{
    protected function setUp(): void
    {
        // Must be an env var: the provider registers (and probes) before
        // defineEnvironment() config overrides apply on Testbench 10.
        $_ENV['OTEL_ENABLE_REACHABILITY_CHECK'] = $_SERVER['OTEL_ENABLE_REACHABILITY_CHECK'] = 'false';
        putenv('OTEL_ENABLE_REACHABILITY_CHECK=false');

        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($_ENV['OTEL_ENABLE_REACHABILITY_CHECK'], $_SERVER['OTEL_ENABLE_REACHABILITY_CHECK']);
        putenv('OTEL_ENABLE_REACHABILITY_CHECK');

        parent::tearDown();
    }

    public function test_bindings_registered_when_check_disabled(): void
    {
        // Endpoint is unreachable (see base TestCase), but the check is off,
        // so registration must proceed anyway.
        $this->assertTrue($this->app->bound('tracer'));
        $this->assertTrue($this->app->bound('metrics'));
    }
}
