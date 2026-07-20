<?php

namespace Laratel\Opentelemetry\Services;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Metrics\MeterInterface;
use Symfony\Component\HttpFoundation\Response;

class MetricsService
{
    /** @var array<string, object> instrument name => instrument */
    public array $metrics = [];

    private ?MeterInterface $meter;

    /** Previous /proc snapshots for delta computation (persist per worker process). */
    private static array $previousCpu = [];

    private static array $previousNetwork = [];

    public function __construct(?MeterInterface $meter = null)
    {
        $this->meter = $meter ?? (app()->bound('metrics') ? app('metrics') : null);

        if ($this->meter !== null) {
            $this->initializeMetrics();
        }
    }

    public function initializeMetrics(): void
    {
        $this->metrics = [
            // HTTP
            'http_request_total' => $this->meter->createCounter('laratel_http_request_total'),
            'http_status_code_total' => $this->meter->createCounter('laratel_http_status_code_total'),
            'http_failed_requests_total' => $this->meter->createCounter('laratel_http_failed_requests_total'),
            'http_request_latency_seconds' => $this->meter->createHistogram('laratel_http_request_latency_seconds', 's'),
            'http_request_size_bytes' => $this->meter->createHistogram('laratel_http_request_size_bytes', 'By'),
            'http_response_size_bytes' => $this->meter->createHistogram('laratel_http_response_size_bytes', 'By'),
            'http_requests_in_progress' => $this->meter->createUpDownCounter('laratel_http_requests_in_progress'),

            // SYSTEM
            'system_cpu_time_seconds_total' => $this->meter->createCounter('laratel_system_cpu_time_seconds_total', 's'),
            'system_memory_usage_bytes' => $this->meter->createHistogram('laratel_system_memory_usage_bytes', 'By'),
            'system_disk_total_bytes' => $this->meter->createHistogram('laratel_system_disk_total_bytes', 'By'),
            'system_disk_free_bytes' => $this->meter->createHistogram('laratel_system_disk_free_bytes', 'By'),
            'system_disk_usage_bytes' => $this->meter->createHistogram('laratel_system_disk_usage_bytes', 'By'),

            // NETWORK
            'system_network_io_bytes_total' => $this->meter->createCounter('laratel_system_network_io_bytes_total', 'By'),
            'system_network_dropped_total' => $this->meter->createCounter('laratel_system_network_dropped_total'),
            'system_network_errors_total' => $this->meter->createCounter('laratel_system_network_errors_total'),
            'active_network_connections' => $this->meter->createHistogram('laratel_active_network_connections'),

            // DATABASE
            'db_query_total' => $this->meter->createCounter('laratel_db_query_total'),
            'db_query_latency_seconds' => $this->meter->createHistogram('laratel_db_query_latency_seconds', 's'),

            // CACHE
            'cache_hit_total' => $this->meter->createCounter('laratel_cache_hit_total'),
            'cache_miss_total' => $this->meter->createCounter('laratel_cache_miss_total'),
            'cache_store_total' => $this->meter->createCounter('laratel_cache_store_total'),
            'cache_delete_total' => $this->meter->createCounter('laratel_cache_delete_total'),

            // ERRORS
            'error_total' => $this->meter->createCounter('laratel_error_total'),
        ];
    }

    /**
     * Count cache operations via Laravel's cache events.
     * Labels deliberately exclude the cache key (unbounded cardinality).
     */
    public function registerCacheEventListeners(): void
    {
        $labels = fn (object $event): array => ['cache.store' => $event->storeName ?? 'default'];

        Event::listen(CacheHit::class, fn (CacheHit $e) => $this->metrics['cache_hit_total']->add(1, $labels($e)));
        Event::listen(CacheMissed::class, fn (CacheMissed $e) => $this->metrics['cache_miss_total']->add(1, $labels($e)));
        Event::listen(KeyWritten::class, fn (KeyWritten $e) => $this->metrics['cache_store_total']->add(1, $labels($e)));
        Event::listen(KeyForgotten::class, fn (KeyForgotten $e) => $this->metrics['cache_delete_total']->add(1, $labels($e)));
    }

    /**
     * Record HTTP metrics for a single request/response.
     */
    public function recordHttpMetrics(Request $request, Response $response, float $startTime): void
    {
        $latency = microtime(true) - $startTime;
        $status = $response->getStatusCode();

        $labels = [
            'http.request.method' => $request->method(),
            'http.route' => $request->route()?->uri() ?? 'unknown',
            'http.response.status_code' => $status,
        ];

        try {
            $this->metrics['http_request_total']->add(1, $labels);
            $this->metrics['http_status_code_total']->add(1, $labels);
            $this->metrics['http_request_latency_seconds']->record($latency, $labels);
            $this->metrics['http_request_size_bytes']->record(strlen($request->getContent()), $labels);
            $this->metrics['http_response_size_bytes']->record(strlen($response->getContent() ?: ''), $labels);

            if ($status >= 400) {
                $this->metrics['http_failed_requests_total']->add(1, array_merge($labels, [
                    'error.type' => $status >= 500 ? 'server_error' : 'client_error',
                ]));
            }
        } catch (\Throwable $e) {
            Log::error("HTTP metrics record failed: {$e->getMessage()}");
        }
    }

    /**
     * Record system-level metrics (CPU, memory, disk)
     */
    public function recordSystemMetrics(): void
    {
        $cpu = $this->getCpuStats();
        foreach ($cpu as $state => $value) {
            $this->metrics['system_cpu_time_seconds_total']->add($value, [
                'state' => $state, 'host' => gethostname(), 'service.name' => config('opentelemetry.service_name'),
            ]);
        }

        $mem = $this->getMemoryInfo();
        foreach ($mem as $key => $value) {
            $this->metrics['system_memory_usage_bytes']->record($value, [
                'type' => $key, 'host' => gethostname(), 'service.name' => config('opentelemetry.service_name'),
            ]);
        }

        $path = config('opentelemetry.disk_path');
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        $used = $total - $free;

        $this->metrics['system_disk_total_bytes']->record($total, [
            'host' => gethostname(), 'service.name' => config('opentelemetry.service_name'),
        ]);
        $this->metrics['system_disk_free_bytes']->record($free, [
            'host' => gethostname(), 'service.name' => config('opentelemetry.service_name'),
        ]);
        $this->metrics['system_disk_usage_bytes']->record($used, [
            'host' => gethostname(), 'service.name' => config('opentelemetry.service_name'),
        ]);
    }

    /**
     * Record database query metrics with bounded labels.
     */
    public function recordDbMetrics(QueryExecuted $query): void
    {
        $operation = strtolower(strtok(ltrim($query->sql), " \t(")) ?: 'other';

        $labels = [
            'db.operation' => $operation,
            'db.connection' => $query->connectionName ?? 'default',
        ];

        $this->metrics['db_query_total']->add(1, $labels);
        $this->metrics['db_query_latency_seconds']->record(($query->time ?? 0) / 1000, $labels);
    }

    /**
     * Record generic error metrics. Never label with the message: it is
     * unbounded and may contain sensitive data.
     */
    public function recordErrorMetrics(\Throwable $e): void
    {
        $this->metrics['error_total']->add(1, [
            'exception.type' => get_class($e),
        ]);
    }

    /**
     * ----------------------------------------------------
     * Internal helpers: CPU, Memory, Network
     * ----------------------------------------------------
     */
    private function getCpuStats(): array
    {
        $states = ['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal'];
        $path = config('opentelemetry.cpu_path');
        if (! file_exists($path)) {
            return [];
        }

        $lines = file($path);
        foreach ($lines as $line) {
            if (preg_match('/^cpu\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $line, $m)) {
                return array_combine($states, array_map('intval', array_slice($m, 1, 8)));
            }
        }

        return [];
    }

    private function getMemoryInfo(): array
    {
        $path = config('opentelemetry.memory_path');
        if (! file_exists($path)) {
            return [];
        }

        $lines = file($path);
        $raw = [];
        foreach ($lines as $line) {
            if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                $raw[strtolower($m[1])] = (int) $m[2] * 1024;
            }
        }

        return [
            'total' => $raw['memtotal'] ?? 0,
            'free' => $raw['memfree'] ?? 0,
            'cached' => $raw['cached'] ?? 0,
            'buffers' => $raw['buffers'] ?? 0,
            'used' => ($raw['memtotal'] ?? 0) - (($raw['memfree'] ?? 0) + ($raw['buffers'] ?? 0) + ($raw['cached'] ?? 0)),
        ];
    }

    /**
     * Record network-level metrics (I/O, drops, errors, active connections)
     */
    public function recordNetworkMetrics(): void
    {
        if (! isset($this->metrics['system_network_io_bytes_total'])) {
            return;
        }

        $networkPath = config('opentelemetry.network_path');
        if (! file_exists($networkPath) || ! is_readable($networkPath)) {
            return;
        }

        $lines = file($networkPath);

        foreach ($lines as $line) {
            // Matches typical /proc/net/dev line format:
            // iface: bytes packets errs drop fifo frame compressed multicast bytes packets errs drop fifo colls carrier compressed
            if (preg_match('/^\s*([\w]+):\s*(.+)$/', $line, $m)) {
                $iface = $m[1];
                $fields = preg_split('/\s+/', trim($m[2]));

                $rxBytes = (int) ($fields[0] ?? 0);
                $rxErrors = (int) ($fields[2] ?? 0);
                $rxDropped = (int) ($fields[3] ?? 0);

                $txBytes = (int) ($fields[8] ?? 0);
                $txErrors = (int) ($fields[10] ?? 0);
                $txDropped = (int) ($fields[11] ?? 0);

                $labels = [
                    'interface' => $iface,
                    'host' => gethostname(),
                    'service.name' => config('opentelemetry.service_name'),
                ];

                $this->metrics['system_network_io_bytes_total']->add($rxBytes + $txBytes, $labels);
                $this->metrics['system_network_dropped_total']->add($rxDropped + $txDropped, $labels);
                $this->metrics['system_network_errors_total']->add($rxErrors + $txErrors, $labels);
            }
        }

        // Active connections (TCP states)
        $connectionPath = config('opentelemetry.connection_path');
        if (file_exists($connectionPath)) {
            $stateMap = [
                '01' => 'ESTABLISHED', '02' => 'SYN_SENT', '03' => 'SYN_RECV',
                '04' => 'FIN_WAIT1', '05' => 'FIN_WAIT2', '06' => 'TIME_WAIT',
                '07' => 'CLOSE', '08' => 'CLOSE_WAIT', '09' => 'LAST_ACK',
                '0A' => 'LISTEN', '0B' => 'CLOSING',
            ];

            $counts = array_fill_keys(array_values($stateMap), 0);
            foreach (file($connectionPath) as $line) {
                if (preg_match('/\s+[0-9A-F]+:\s+[0-9A-F]+\s+[0-9A-F]+\s+([0-9A-F]{2})/', $line, $m)) {
                    $hex = strtoupper($m[1]);
                    if (isset($stateMap[$hex])) {
                        $counts[$stateMap[$hex]]++;
                    }
                }
            }

            foreach ($counts as $state => $count) {
                $this->metrics['active_network_connections']->record($count, [
                    'state' => $state, 'host' => gethostname(), 'service.name' => config('opentelemetry.service_name'),
                ]);
            }
        }
    }
}
