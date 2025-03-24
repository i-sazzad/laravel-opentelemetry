<?php

namespace Laratel\Opentelemetry\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use OpenTelemetry\SDK\Metrics\Counter;
use OpenTelemetry\SDK\Metrics\Histogram;
use Throwable;

class MetricsService
{
    protected mixed $meter;
    public mixed $metrics;

    public function __construct()
    {
        $this->meter = app('metrics');
        $this->metrics = [];

        if(!$this->meter){
            return null;  // Return null if metrics is not available
        }

        $this->initializeMetrics();
    }

    public function initializeMetrics(): void
    {
        $this->metrics = [
            // HTTP metrics
            'requestCount' => $this->meter->createCounter('http_request_count', 'Counts HTTP requests'),
            'statusCodeCount' => $this->meter->createCounter('http_status_code_count', 'Counts HTTP responses by status code'),
            'requestLatency' => $this->meter->createHistogram('http_request_latency', 'Records HTTP request latency'),
            'requestSize' => $this->meter->createHistogram('http_request_size_bytes', 'Tracks HTTP request sizes in bytes'),
            'responseSize' => $this->meter->createHistogram('http_response_size_bytes', 'Tracks HTTP response sizes in bytes'),

            // System metrics
            'cpuTime' => $this->meter->createCounter('system_cpu_time_seconds_total', 'Tracks CPU time spent by state and CPU'),
            'memoryUsage' => $this->meter->createGauge('system_memory_usage_bytes', 'Reports memory usage by state'),
            'diskUsage' => $this->meter->createHistogram('system_disk_usage_bytes', 'Records available disk space in bytes'),
            'uptime' => $this->meter->createGauge('application_uptime_seconds', 'Tracks application uptime in seconds'),

            // Network metrics
            'networkIO' => $this->meter->createCounter('system_network_io_bytes_total', 'Tracks network IO in bytes'),
            'networkPackets' => $this->meter->createCounter('system_network_packets_total', 'Tracks total network packets sent/received'),
            'networkDropped' => $this->meter->createCounter('system_network_dropped_total', 'Tracks dropped network packets'),
            'networkErrors' => $this->meter->createCounter('system_network_errors_total', 'Tracks network errors by direction'),
            'networkInbound' => $this->meter->createHistogram('network_inbound_bytes', 'Records inbound network traffic in bytes'),
            'networkOutbound' => $this->meter->createHistogram('network_outbound_bytes', 'Records outbound network traffic in bytes'),
            'activeConnections' => $this->meter->createGauge('active_network_connections', 'Counts active network connections'),

            // Database metrics
            'dbQueryCount' => $this->meter->createCounter('db_query_count', 'Counts total database queries'),
            'dbQueryLatency' => $this->meter->createHistogram('db_query_latency', 'Records database query latency'),
            'dbErrorCount' => $this->meter->createCounter('db_error_count', 'Counts database query errors'),

            // Error metric (added)
            'errorCount' => $this->meter->createCounter('error_count', 'Counts errors during request processing'),
        ];
    }

    public function recordMetrics(Request $request, Response $response, $startTime): void
    {
        try {
            $latency = microtime(true) - $startTime;
            $this->metrics['requestCount']->add(1, ['method' => $request->method(), 'route' => $request->path()]);
            $this->metrics['statusCodeCount']->add(1, ['status_code' => $response->getStatusCode(), 'method' => $request->method()]);
            $this->metrics['requestLatency']->record($latency, ['method' => $request->method(), 'route' => $request->path()]);
            $this->metrics['requestSize']->record(strlen($request->getContent()), ['method' => $request->method()]);
            $this->metrics['responseSize']->record(strlen($response->getContent()), ['method' => $request->method()]);
        } catch (Throwable $e) {
            Log::error('Error recording HTTP metrics: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'stack' => $e->getTraceAsString()]);
        }
    }

    public function recordSystemMetrics(): void
    {
        try {
            $cpuStats = $this->getCpuStats();
            foreach ($cpuStats as $state => $time) {
                $this->metrics['cpuTime']->add($time, ['state' => $state, 'host' => gethostname()]);
            }

            $memoryInfo = $this->getMemoryInfo();
            foreach ($memoryInfo as $state => $value) {
                $this->metrics['memoryUsage']->record($value, ['state' => $state, 'host' => gethostname()]);
            }

            $diskUsage = disk_free_space(config('opentelemetry.disk_path', '/'));
            $this->metrics['diskUsage']->record($diskUsage, ['host' => gethostname()]);
        } catch (Throwable $e) {
            Log::error('Error recording system metrics: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'stack' => $e->getTraceAsString()]);
        }
    }

    public function recordNetworkMetrics(): void
    {
        try {
            $networkStats = $this->getNetworkStats();
            foreach ($networkStats as $metric => $data) {
                foreach ($data as $direction => $value) {
                    if (isset($this->metrics[$metric])) {
                        if ($this->metrics[$metric] instanceof Histogram) {
                            $this->metrics[$metric]->record($value, ['direction' => $direction, 'host' => gethostname()]);
                        } elseif ($this->metrics[$metric] instanceof Counter) {
                            $this->metrics[$metric]->add($value, ['direction' => $direction, 'host' => gethostname()]);
                        }
                    }
                }
            }

            $connections = $this->getNetworkConnections();
            foreach ($connections as $state => $count) {
                $this->metrics['activeConnections']->record($count, ['state' => $state, 'host' => gethostname()]);
            }
        } catch (Throwable $e) {
            Log::error('Error recording network metrics: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'stack' => $e->getTraceAsString()]);
        }
    }

    public function recordDbMetrics($query): void
    {
        try {
            $executionTime = $query->time / 1000; // Convert milliseconds to seconds
            $this->metrics['dbQueryCount']->add(1, ['query' => $query->sql, 'host' => gethostname()]);
            $this->metrics['dbQueryLatency']->record($executionTime, ['query' => $query->sql, 'host' => gethostname()]);

            if (isset($query->error)) {
                $this->metrics['dbErrorCount']->add(1, ['error' => $query->error, 'host' => gethostname()]);
            }
        } catch (Throwable $e) {
            Log::error('Error recording DB metrics: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'stack' => $e->getTraceAsString()]);
        }
    }

    public function wrapCacheOperations(): void
    {
        Cache::extend('otel', function ($app, $config) {
            $store = Cache::driver($config['driver']);
            return new class($store, $this->metrics) {
                protected $store;
                protected $metrics;

                public function __construct($store, $metrics)
                {
                    $this->store = $store;
                    $this->metrics = $metrics;
                }

                public function get($key)
                {
                    $value = $this->store->get($key);
                    $metric = $value ? 'cacheHitCount' : 'cacheMissCount';
                    $this->metrics[$metric]->add(1, ['key' => $key, 'host' => gethostname()]);
                    return $value;
                }

                public function put($key, $value, $ttl = null): void
                {
                    $this->store->put($key, $value, $ttl);
                    $this->metrics['cacheStoreCount']->add(1, ['key' => $key, 'host' => gethostname()]);
                }

                public function forget($key): void
                {
                    $this->store->forget($key);
                    $this->metrics['cacheDeleteCount']->add(1, ['key' => $key, 'host' => gethostname()]);
                }

                public function __call($method, $parameters)
                {
                    return $this->store->$method(...$parameters);
                }
            };
        });
    }

    public function recordErrorMetrics($e)
    {
        return $this->metrics['errorCount']->add(1, ['error' => $e->getMessage(), 'host' => gethostname()]);
    }

    private function getCpuStats(): array
    {
        $cpuStates = ['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal'];
        $cpuStats = [];

        if (file_exists(config('opentelemetry.cpu_path', '/proc/stat'))) {
            $lines = file(config('opentelemetry.cpu_path', '/proc/stat'));
            foreach ($lines as $line) {
                if (preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $line, $matches)) {
                    foreach ($cpuStates as $index => $state) {
                        $cpuStats[$state] = (int) $matches[$index + 1];
                    }
                    break;
                }
            }
        }

        return $cpuStats;
    }

    private function getMemoryInfo(): array
    {
        $memoryInfo = [];

        if (file_exists(config('opentelemetry.memory_path', '/proc/meminfo'))) {
            $lines = file(config('opentelemetry.memory_path', '/proc/meminfo'));
            $rawMemory = [];
            foreach ($lines as $line) {
                if (preg_match('/^(\w+):\s+(\d+)\s+kB/', $line, $matches)) {
                    $key = strtolower(str_replace(['(', ')'], '', $matches[1]));
                    $rawMemory[$key] = (int) $matches[2] / 1024; // Convert KB to MB
                }
            }

            $memoryInfo = [
                'buffered' => $rawMemory['buffers'] ?? 0,
                'cached' => $rawMemory['cached'] ?? 0,
                'free' => $rawMemory['memfree'] ?? 0,
                'slab_reclaimable' => $rawMemory['slab_reclaimable'] ?? 0,
                'slab_unreclaimable' => $rawMemory['slab_unreclaimable'] ?? 0,
                'used' => ($rawMemory['memtotal'] ?? 0) - ($rawMemory['memfree'] ?? 0) - ($rawMemory['buffers'] ?? 0) - ($rawMemory['cached'] ?? 0),
            ];
        }

        return $memoryInfo;
    }

    private function getNetworkConnections(): array
    {
        $states = config('opentelemetry.network_states', [
            'ESTABLISHED', 'CLOSE_WAIT', 'TIME_WAIT', 'LISTEN', 'SYN_SENT', 'SYN_RECV',
        ]);
        $connectionCounts = array_fill_keys($states, 0);

        if (file_exists(config('opentelemetry.connection_path', '/proc/net/tcp'))) {
            $lines = file(config('opentelemetry.connection_path', '/proc/net/tcp'));
            foreach ($lines as $line) {
                foreach ($states as $state) {
                    if (str_contains($line, $state)) {
                        $connectionCounts[$state]++;
                    }
                }
            }
        }

        return $connectionCounts;
    }

    private function getNetworkStats(): array
    {
        $stats = [
            'networkIO' => ['receive' => 0, 'transmit' => 0],
            'networkPackets' => ['receive' => 0, 'transmit' => 0],
            'networkDropped' => ['receive' => 0, 'transmit' => 0],
            'networkErrors' => ['receive' => 0, 'transmit' => 0],
            'networkInbound' => ['bytes' => 0, 'packets' => 0],
            'networkOutbound' => ['bytes' => 0, 'packets' => 0],
        ];

        $networkPath = config('opentelemetry.network_path', '/proc/net/dev');
        if (!file_exists($networkPath) || !is_readable($networkPath)) {
            Log::warning("Network stats file {$networkPath} is not accessible.");
            return $stats;
        }

        $lines = file($networkPath);
        foreach ($lines as $line) {
            if (preg_match('/^\s*(?<interface>[\w]+):\s*(?<receive_bytes>\d+)\s+(?<receive_packets>\d+)\s+\d+\s+\d+\s+(?<receive_dropped>\d+)\s+(?<receive_errors>\d+)\s+\d+\s*(?<transmit_bytes>\d+)\s+(?<transmit_packets>\d+)\s+\d+\s+\d+\s+(?<transmit_dropped>\d+)\s+(?<transmit_errors>\d+)/', $line, $matches)) {
                // Aggregate general network stats
                $stats['networkIO']['receive'] += (int)$matches['receive_bytes'];
                $stats['networkIO']['transmit'] += (int)$matches['transmit_bytes'];
                $stats['networkDropped']['receive'] += (int)$matches['receive_dropped'];
                $stats['networkDropped']['transmit'] += (int)$matches['transmit_dropped'];
                $stats['networkErrors']['receive'] += (int)$matches['receive_errors'];
                $stats['networkErrors']['transmit'] += (int)$matches['transmit_errors'];

                // Capture inbound and outbound specific stats
                $stats['networkInbound']['bytes'] += (int)$matches['receive_bytes'];
                $stats['networkInbound']['packets'] += (int)$matches['receive_packets'];
                $stats['networkOutbound']['bytes'] += (int)$matches['transmit_bytes'];
                $stats['networkOutbound']['packets'] += (int)$matches['transmit_packets'];
            }
        }

        return $stats;
    }
}
