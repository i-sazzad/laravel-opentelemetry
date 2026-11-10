<?php

namespace Laratel\Opentelemetry\Support;

use Illuminate\Support\Facades\Log;

/**
 * One shared, cached probe of the OTLP endpoint, used by the service
 * provider and the log channel factory so both agree on the result and
 * the collector is contacted at most once per process.
 */
final class Reachability
{
    private static ?bool $reachable = null;

    public static function check(): bool
    {
        if (! config('opentelemetry.enable_reachability_check', true)) {
            return true;
        }

        if (self::$reachable !== null) {
            return self::$reachable;
        }

        $endpoint = config('opentelemetry.endpoint');
        $parts = is_string($endpoint) ? parse_url($endpoint) : false;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        // Cache the result BEFORE logging: when the default log channel is
        // (or stacks) the otel channel, logging re-enters this check.
        if (! $host) {
            self::$reachable = false;
            self::log('error', 'Invalid OTEL_EXPORTER_OTLP_ENDPOINT; OpenTelemetry is disabled.', ['endpoint' => $endpoint]);

            return false;
        }

        // Without an explicit port the exporter uses the scheme default,
        // so the probe must too (an https endpoint behind a load balancer
        // listens on 443, not on the collector's 4317/4318).
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80);
        $timeout = (float) config('opentelemetry.reachability_timeout', 0.1);

        $connection = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);

        if (is_resource($connection)) {
            fclose($connection);

            return self::$reachable = true;
        }

        self::$reachable = false;
        self::log('warning', "OTLP endpoint unreachable: {$host}:{$port}. OpenTelemetry is disabled.", [
            'error' => trim((string) $errstr),
        ]);

        return false;
    }

    /** Forget the cached result (long-running workers, tests). */
    public static function reset(): void
    {
        self::$reachable = null;
    }

    private static function log(string $level, string $message, array $context): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (\Throwable) {
            // Telemetry must never break the host application.
        }
    }
}
