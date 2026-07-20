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
     * Record system-level metrics. CPU is reported as time deltas since
     * the previous reading (a counter must receive deltas, and /proc/stat
     * exposes cumulative USER_HZ ticks — 100 per second on Linux).
     */
    public function recordSystemMetrics(): void
    {
        if (! config('opentelemetry.system_metrics_enabled', true)) {
            return;
        }

        $cpu = $this->getCpuStats();
        if ($cpu !== []) {
            if (self::$previousCpu !== []) {
                foreach ($cpu as $state => $ticks) {
                    $delta = $ticks - (self::$previousCpu[$state] ?? 0);
                    if ($delta > 0) {
                        $this->metrics['system_cpu_time_seconds_total']->add($delta / 100, ['state' => $state]);
                    }
                }
            }
            self::$previousCpu = $cpu;
        }

        foreach ($this->getMemoryInfo() as $type => $bytes) {
            $this->metrics['system_memory_usage_bytes']->record($bytes, ['type' => $type]);
        }

        $path = config('opentelemetry.disk_path');
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if ($total !== false && $free !== false) {
            $this->metrics['system_disk_total_bytes']->record($total);
            $this->metrics['system_disk_free_bytes']->record($free);
            $this->metrics['system_disk_usage_bytes']->record($total - $free);
        }
    }

    /**
     * Record database query metrics with bounded labels.
     */
    public function recordDbMetrics(QueryExecuted $query): void
    {
        $operation = strtolower(strtok(ltrim($query->sql), " \t(")) ?: 'other';

        $labels = [
            'db.operation' => $operation,
            'db.connection' => $query->connectionName ?: 'default',
        ];

        $this->metrics['db_query_total']->add(1, $labels);
        $this->metrics['db_query_latency_seconds']->record($query->time / 1000, $labels);
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
     * Record network I/O as deltas since the previous /proc/net/dev reading,
     * split by interface and direction. TCP connection-state gauge readings
     * are recorded as-is (they are point-in-time counts, not cumulative).
     */
    public function recordNetworkMetrics(): void
    {
        if (! config('opentelemetry.system_metrics_enabled', true)) {
            return;
        }

        $networkPath = config('opentelemetry.network_path');
        if (is_readable($networkPath)) {
            foreach (file($networkPath) as $line) {
                if (! preg_match('/^\s*([\w]+):\s*(.+)$/', $line, $m)) {
                    continue;
                }

                $iface = $m[1];
                $fields = preg_split('/\s+/', trim($m[2]));

                $current = [
                    'receive' => [
                        'bytes' => (int) ($fields[0] ?? 0),
                        'errors' => (int) ($fields[2] ?? 0),
                        'dropped' => (int) ($fields[3] ?? 0),
                    ],
                    'transmit' => [
                        'bytes' => (int) ($fields[8] ?? 0),
                        'errors' => (int) ($fields[10] ?? 0),
                        'dropped' => (int) ($fields[11] ?? 0),
                    ],
                ];

                $previous = self::$previousNetwork[$iface] ?? null;
                self::$previousNetwork[$iface] = $current;
                if ($previous === null) {
                    continue;
                }

                foreach (['receive', 'transmit'] as $direction) {
                    $labels = ['network.interface' => $iface, 'network.io.direction' => $direction];
                    $this->addCounterDelta('system_network_io_bytes_total', $current[$direction]['bytes'] - $previous[$direction]['bytes'], $labels);
                    $this->addCounterDelta('system_network_errors_total', $current[$direction]['errors'] - $previous[$direction]['errors'], $labels);
                    $this->addCounterDelta('system_network_dropped_total', $current[$direction]['dropped'] - $previous[$direction]['dropped'], $labels);
                }
            }
        }

        $this->recordTcpConnectionStates();
    }

    private function addCounterDelta(string $metric, int $delta, array $labels): void
    {
        if ($delta > 0) {
            $this->metrics[$metric]->add($delta, $labels);
        }
    }

    private function recordTcpConnectionStates(): void
    {
        $connectionPath = config('opentelemetry.connection_path');
        if (! is_readable($connectionPath)) {
            return;
        }

        $stateMap = [
            '01' => 'ESTABLISHED', '02' => 'SYN_SENT', '03' => 'SYN_RECV',
            '04' => 'FIN_WAIT1', '05' => 'FIN_WAIT2', '06' => 'TIME_WAIT',
            '07' => 'CLOSE', '08' => 'CLOSE_WAIT', '09' => 'LAST_ACK',
            '0A' => 'LISTEN', '0B' => 'CLOSING',
        ];

        $counts = array_fill_keys(array_values($stateMap), 0);
        foreach (file($connectionPath) as $line) {
            if (preg_match('/\s+[0-9A-F]+:\s+[0-9A-F]+\s+[0-9A-F]+\s+([0-9A-F]{2})/', $line, $m)) {
                $state = $stateMap[strtoupper($m[1])] ?? null;
                if ($state !== null) {
                    $counts[$state]++;
                }
            }
        }

        foreach ($counts as $state => $count) {
            $this->metrics['active_network_connections']->record($count, ['network.state' => $state]);
        }
    }
}
