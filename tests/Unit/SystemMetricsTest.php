<?php

namespace Laratel\Opentelemetry\Tests\Unit;

use Laratel\Opentelemetry\Services\MetricsService;
use Laratel\Opentelemetry\Tests\TestCase;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\API\Metrics\UpDownCounterInterface;

class SystemMetricsTest extends TestCase
{
    private string $statFile;

    protected function setUp(): void
    {
        // Base TestCase already resets the static $previousCpu / $previousNetwork
        // snapshots between tests — no extra reset needed here.
        parent::setUp();

        $this->statFile = tempnam(sys_get_temp_dir(), 'otel-stat-');
        config()->set('opentelemetry.cpu_path', $this->statFile);
        config()->set('opentelemetry.memory_path', '/nonexistent-meminfo');
        config()->set('opentelemetry.disk_path', '/nonexistent-disk-path');
        config()->set('opentelemetry.network_path', '/nonexistent-netdev');
        config()->set('opentelemetry.connection_path', '/nonexistent-tcp');
    }

    protected function tearDown(): void
    {
        @unlink($this->statFile);
        parent::tearDown();
    }

    protected function makeService(): MetricsService
    {
        $meter = $this->createMock(MeterInterface::class);
        $meter->method('createCounter')
            ->willReturnCallback(fn () => $this->createMock(CounterInterface::class));
        $meter->method('createHistogram')
            ->willReturnCallback(fn () => $this->createMock(HistogramInterface::class));
        $meter->method('createUpDownCounter')
            ->willReturnCallback(fn () => $this->createMock(UpDownCounterInterface::class));

        return new MetricsService($meter);
    }

    public function test_first_reading_only_snapshots_and_records_nothing(): void
    {
        file_put_contents($this->statFile, "cpu  100 0 50 1000 0 0 0 0\n");

        $service = $this->makeService();
        $spy = $this->createMock(CounterInterface::class);
        $spy->expects($this->never())->method('add');
        $service->metrics['system_cpu_time_seconds_total'] = $spy;

        $service->recordSystemMetrics();
    }

    public function test_second_reading_records_deltas_in_seconds(): void
    {
        file_put_contents($this->statFile, "cpu  100 0 50 1000 0 0 0 0\n");

        $service = $this->makeService();
        $calls = [];
        $spy = $this->createMock(CounterInterface::class);
        $spy->method('add')->willReturnCallback(function ($value, $labels) use (&$calls) {
            $calls[$labels['state']] = $value;
        });
        $service->metrics['system_cpu_time_seconds_total'] = $spy;

        $service->recordSystemMetrics(); // snapshot

        // +50 user ticks, +10 system ticks, +1000 idle ticks (100 ticks = 1s)
        file_put_contents($this->statFile, "cpu  150 0 60 2000 0 0 0 0\n");
        $service->recordSystemMetrics();

        $this->assertEquals(['user' => 0.5, 'system' => 0.1, 'idle' => 10.0], $calls);
    }

    public function test_disabled_flag_skips_everything(): void
    {
        config()->set('opentelemetry.system_metrics_enabled', false);
        file_put_contents($this->statFile, "cpu  100 0 50 1000 0 0 0 0\n");

        $service = $this->makeService();
        $spy = $this->createMock(CounterInterface::class);
        $spy->expects($this->never())->method('add');
        $service->metrics['system_cpu_time_seconds_total'] = $spy;

        $service->recordSystemMetrics();
        $service->recordSystemMetrics();
    }
}
