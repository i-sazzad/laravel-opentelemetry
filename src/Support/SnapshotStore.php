<?php

namespace Laratel\Opentelemetry\Support;

/**
 * Small keyed state shared between requests.
 *
 * Under PHP-FPM every request is a new process, so in-memory state is
 * always empty; values are therefore kept in a JSON file (`state_path`)
 * and every read-modify-write happens under an exclusive lock. Without a
 * usable file the state lives in memory for the current process only.
 *
 * Used for the previous readings of cumulative OS counters (/proc/stat,
 * /proc/net/dev) and for the "collector is down" marker.
 */
final class SnapshotStore
{
    /** In-process fallback when no state file is configured or writable. */
    private static array $memory = [];

    /**
     * Store $current under $key and return the value it replaces (an empty
     * array when there is none yet). Atomic across processes, so each
     * interval between two readings is handed to exactly one request.
     */
    public static function exchange(string $key, array $current): array
    {
        return self::update(static function (array $state) use ($key, $current): array {
            $previous = is_array($state[$key] ?? null) ? $state[$key] : [];
            $state[$key] = $current;

            return [$state, $previous];
        });
    }

    public static function get(string $key): array
    {
        return self::update(static fn (array $state): array => [
            null,
            is_array($state[$key] ?? null) ? $state[$key] : [],
        ]);
    }

    public static function put(string $key, array $value): void
    {
        self::update(static function (array $state) use ($key, $value): array {
            $state[$key] = $value;

            return [$state, []];
        });
    }

    /** Forget in-process state (tests). */
    public static function reset(): void
    {
        self::$memory = [];
    }

    /**
     * Run $callback on the current state under a lock. It returns
     * [new state or null to leave it untouched, result].
     *
     * @param  callable(array): array{0: ?array, 1: array}  $callback
     */
    private static function update(callable $callback): array
    {
        $path = config('opentelemetry.state_path');
        $handle = is_string($path) && $path !== '' ? @fopen($path, 'c+') : false;

        if ($handle === false || ! @flock($handle, LOCK_EX)) {
            if ($handle !== false) {
                fclose($handle);
            }

            [$state, $result] = $callback(self::$memory);
            self::$memory = $state ?? self::$memory;

            return $result;
        }

        try {
            $state = json_decode((string) stream_get_contents($handle), true);
            [$state, $result] = $callback(is_array($state) ? $state : []);

            if ($state !== null) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string) json_encode($state));
                fflush($handle);
            }

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
