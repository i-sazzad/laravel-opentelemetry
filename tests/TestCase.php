<?php

namespace Laratel\Opentelemetry\Tests;

use Laratel\Opentelemetry\Logger\OtelLoggerFactory;
use Laratel\Opentelemetry\Middleware\OpenTelemetryMetricsMiddleware;
use Laratel\Opentelemetry\Providers\OpenTelemetryServiceProvider;
use Laratel\Opentelemetry\Services\MetricsService;
use Laratel\Opentelemetry\Services\TraceService;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Static properties leak between tests in the same PHP process.
     * Some entries only exist after later plans (or stop existing after
     * refactors) — missing properties are skipped, so this list stays
     * valid across plans 01-04 without edits.
     *
     * @var array<array{class-string, string, mixed}>
     */
    private const STATIC_RESETS = [
        [OpenTelemetryServiceProvider::class, 'isReachable', null],
        [OtelLoggerFactory::class, 'isReachable', null],
        [OtelLoggerFactory::class, 'loggerProvider', null],
        [TraceService::class, 'listenerRegistered', false],
        [TraceService::class, 'exclusionPattern', null],
        [MetricsService::class, 'previousCpu', []],
        [MetricsService::class, 'previousNetwork', []],
        [OpenTelemetryMetricsMiddleware::class, 'cacheWrapped', false],
        [OpenTelemetryMetricsMiddleware::class, 'cacheListenersRegistered', false],
        [OpenTelemetryMetricsMiddleware::class, 'dbListenerRegistered', false],
    ];

    protected function setUp(): void
    {
        // Package providers register BEFORE defineEnvironment() on Testbench 10,
        // so the endpoint must be forced via env vars before the app is created —
        // otherwise the reachability probe may hit a real local collector.
        $_ENV['OTEL_EXPORTER_OTLP_ENDPOINT'] = $_SERVER['OTEL_EXPORTER_OTLP_ENDPOINT'] = 'http://127.0.0.1:1';
        $_ENV['OTEL_EXPORTER_OTLP_PROTOCOL'] = $_SERVER['OTEL_EXPORTER_OTLP_PROTOCOL'] = 'http';
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=http://127.0.0.1:1');
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=http');

        foreach (self::STATIC_RESETS as [$class, $property, $default]) {
            try {
                $prop = new \ReflectionProperty($class, $property);
                if ($prop->isStatic()) {
                    $prop->setValue(null, $default);
                }
            } catch (\ReflectionException) {
                // Property removed or not introduced yet — nothing to reset.
            }
        }

        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [OpenTelemetryServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // Port 1 refuses connections immediately: fast, deterministic "collector down".
        $app['config']->set('opentelemetry.endpoint', 'http://127.0.0.1:1');
        $app['config']->set('opentelemetry.protocol', 'http');
    }
}
