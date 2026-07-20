<?php

namespace Laratel\Opentelemetry\Tests\Feature;

use Laratel\Opentelemetry\Tests\TestCase;

class ConfigDefaultsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The base TestCase forces OTEL_EXPORTER_OTLP_* env vars so the provider
        // never reaches a real collector; clear them here so `require`-ing the
        // config file below yields the raw package defaults.
        foreach (['OTEL_EXPORTER_OTLP_ENDPOINT', 'OTEL_EXPORTER_OTLP_PROTOCOL'] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }
    }

    public function test_default_protocol_and_endpoint_agree(): void
    {
        $config = require __DIR__.'/../../src/config/opentelemetry.php';

        $this->assertSame('http', $config['protocol']);
        $this->assertSame('http://localhost:4318', $config['endpoint']);
    }

    public function test_reachability_timeout_default(): void
    {
        $config = require __DIR__.'/../../src/config/opentelemetry.php';

        $this->assertSame(0.1, $config['reachability_timeout']);
    }
}
