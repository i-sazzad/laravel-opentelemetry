<?php

namespace Laratel\Opentelemetry\Support;

use Illuminate\Support\Facades\Log;

/**
 * Decides whether telemetry may talk to the collector right now.
 *
 * Two mechanisms, both shared by the service provider, the log channel and
 * the exporters:
 *
 *  - a TCP probe of the OTLP endpoint, at most once per process;
 *  - a circuit breaker: when the probe or a real export fails, the
 *    collector is marked down for `retry_after` seconds. The marker lives
 *    in the state file, so under PHP-FPM the following requests neither
 *    probe, nor export, nor log again until the cool-down has passed.
 *
 * A dead collector therefore costs one failed attempt per cool-down
 * instead of a timeout on every request.
 */
final class Reachability
{
    private const STATE_KEY = 'collector_down';

    /** Result for this process; a negative result expires with the cool-down. */
    private static ?bool $reachable = null;

    private static int $expiresAt = 0;

    public static function check(): bool
    {
        if (self::$reachable !== null && (self::$reachable || time() < self::$expiresAt)) {
            return self::$reachable;
        }

        $downUntil = (int) (SnapshotStore::get(self::STATE_KEY)['until'] ?? 0);
        if ($downUntil > time()) {
            self::$expiresAt = $downUntil;

            return self::$reachable = false;
        }

        if (! config('opentelemetry.enable_reachability_check', true)) {
            return self::$reachable = true;
        }

        $endpoint = config('opentelemetry.endpoint');
        $parts = is_string($endpoint) ? parse_url($endpoint) : false;
        $host = is_array($parts) ? ($parts['host'] ?? null) : null;

        if (! $host) {
            self::markDown('Invalid OTEL_EXPORTER_OTLP_ENDPOINT', ['endpoint' => $endpoint], 'error');

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

        self::markDown("OTLP endpoint unreachable: {$host}:{$port}", ['error' => trim((string) $errstr)]);

        return false;
    }

    /**
     * Open the circuit: stop probing and exporting for `retry_after`
     * seconds, in this process and (through the state file) in the others.
     */
    public static function markDown(string $reason, array $context = [], string $level = 'warning'): void
    {
        $retryAfter = max(1, (int) config('opentelemetry.retry_after', 30));

        // Set the result BEFORE logging: when the default log channel is
        // (or stacks) the otel channel, logging re-enters check().
        self::$reachable = false;
        self::$expiresAt = time() + $retryAfter;

        try {
            SnapshotStore::put(self::STATE_KEY, ['until' => self::$expiresAt]);
            Log::log($level, "[OTEL] {$reason}. Telemetry is paused for {$retryAfter}s.", $context);
        } catch (\Throwable) {
            // Telemetry must never break the host application.
        }
    }

    /** Forget the result and the down marker (tests, manual recovery). */
    public static function reset(): void
    {
        self::$reachable = null;
        self::$expiresAt = 0;

        try {
            SnapshotStore::put(self::STATE_KEY, []);
        } catch (\Throwable) {
            // Nothing to forget.
        }
    }
}
