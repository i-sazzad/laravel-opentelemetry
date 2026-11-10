<?php

namespace Laratel\Opentelemetry\Tests\Unit;

use Laratel\Opentelemetry\Services\MetricsService;
use Laratel\Opentelemetry\Tests\TestCase;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\GaugeInterface;
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
        $meter->method('createGauge')
            ->willReturnCallback(fn () => $this->createMock(GaugeInterface::class));
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

    public function test_network_io_records_deltas_per_direction(): void
    {
        $netFile = tempnam(sys_get_temp_dir(), 'otel-net-');
        config()->set('opentelemetry.network_path', $netFile);
        file_put_contents($netFile, "  eth0: 1000 10 0 0 0 0 0 0 2000 20 0 0 0 0 0 0\n");

        $service = $this->makeService();
        $calls = [];
        $spy = $this->createMock(CounterInterface::class);
        $spy->method('add')->willReturnCallback(function ($value, $labels) use (&$calls) {
            $calls[$labels['network.io.direction']] = $value;
        });
        $service->metrics['system_network_io_bytes_total'] = $spy;

        $service->recordNetworkMetrics(); // snapshot only
        $this->assertSame([], $calls);

        file_put_contents($netFile, "  eth0: 1500 15 0 0 0 0 0 0 2100 21 0 0 0 0 0 0\n");
        $service->recordNetworkMetrics();

        $this->assertSame(['receive' => 500, 'transmit' => 100], $calls);
        @unlink($netFile);
    }

    public function test_interface_names_with_punctuation_are_parsed(): void
    {
        $netFile = tempnam(sys_get_temp_dir(), 'otel-net-');
        config()->set('opentelemetry.network_path', $netFile);
        file_put_contents($netFile, "br-1a2b: 100 1 0 0 0 0 0 0 100 1 0 0 0 0 0 0\n");

        $service = $this->makeService();
        $interfaces = [];
        $spy = $this->createMock(CounterInterface::class);
        $spy->method('add')->willReturnCallback(function ($value, $labels) use (&$interfaces) {
            $interfaces[] = $labels['network.interface'];
        });
        $service->metrics['system_network_io_bytes_total'] = $spy;

        $service->recordNetworkMetrics();
        file_put_contents($netFile, "br-1a2b: 300 2 0 0 0 0 0 0 100 1 0 0 0 0 0 0\n");
        $service->recordNetworkMetrics();

        $this->assertSame(['br-1a2b'], $interfaces);
        @unlink($netFile);
    }

    public function test_tcp_connection_states_are_counted(): void
    {
        $tcpFile = tempnam(sys_get_temp_dir(), 'otel-tcp-');
        config()->set('opentelemetry.connection_path', $tcpFile);
        // Real /proc/net/tcp layout: addresses are HEX:PORT, state is column 4.
        file_put_contents($tcpFile, implode("\n", [
            '  sl  local_address rem_address   st tx_queue rx_queue tr tm->when retrnsmt   uid  timeout inode',
            '   0: 0100007F:0CEA 00000000:0000 0A 00000000:00000000 00:00000000 00000000   114        0 21307 1',
            '   1: 0100007F:1F90 0100007F:D2F4 01 00000000:00000000 00:00000000 00000000  1000        0 31102 1',
            '   2: 0100007F:D2F4 0100007F:1F90 01 00000000:00000000 00:00000000 00000000  1000        0 31103 1',
        ])."\n");

        $service = $this->makeService();
        $counts = [];
        $spy = $this->createMock(GaugeInterface::class);
        $spy->method('record')->willReturnCallback(function ($value, $labels) use (&$counts) {
            $counts[$labels['network.state']] = $value;
        });
        $service->metrics['active_network_connections'] = $spy;

        $service->recordNetworkMetrics();

        $this->assertSame(2, $counts['ESTABLISHED']);
        $this->assertSame(1, $counts['LISTEN']);
        $this->assertSame(0, $counts['TIME_WAIT']);
        @unlink($tcpFile);
    }

    public function test_point_in_time_readings_are_gauges_not_histograms(): void
    {
        $service = $this->makeService();

        foreach (['system_memory_usage_bytes', 'system_disk_usage_bytes', 'active_network_connections'] as $name) {
            $this->assertInstanceOf(GaugeInterface::class, $service->metrics[$name]);
        }
    }

    public function test_host_metric_failures_never_escape(): void
    {
        $service = $this->makeService();
        $broken = $this->createMock(GaugeInterface::class);
        $broken->method('record')->willThrowException(new \RuntimeException('exporter exploded'));
        $service->metrics['system_disk_total_bytes'] = $broken;
        config()->set('opentelemetry.disk_path', '/');

        $service->recordHostMetrics();

        $this->addToAssertionCount(1);
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
