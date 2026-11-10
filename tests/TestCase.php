<?php

namespace Laratel\Opentelemetry\Tests;

use Laratel\Opentelemetry\Logger\OtelLoggerFactory;
use Laratel\Opentelemetry\Providers\OpenTelemetryServiceProvider;
use Laratel\Opentelemetry\Services\MetricsService;
use Laratel\Opentelemetry\Services\TraceService;
use Laratel\Opentelemetry\Support\Reachability;
use Laratel\Opentelemetry\Support\SnapshotStore;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Static properties leak between tests in the same PHP process.
     * Missing properties are skipped, so a refactor that removes one does
     * not break the suite.
     *
     * @var array<array{class-string, string, mixed}>
     */
    private const STATIC_RESETS = [
        [Reachability::class, 'reachable', null],
        [Reachability::class, 'expiresAt', 0],
        [OtelLoggerFactory::class, 'loggerProvider', null],
        [TraceService::class, 'listeningOn', null],
        [MetricsService::class, 'listeningOn', null],
        [SnapshotStore::class, 'memory', []],
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
        // Same reason: keep shared state in memory, never in a file that
        // would leak a "collector down" marker from one test into the next.
        $_ENV['OTEL_STATE_PATH'] = $_SERVER['OTEL_STATE_PATH'] = '';
        putenv('OTEL_STATE_PATH=');

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
        // Keep counter snapshots in memory so tests never share a state file.
        $app['config']->set('opentelemetry.state_path', null);
    }
}
