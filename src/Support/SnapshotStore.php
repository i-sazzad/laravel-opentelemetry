<?php

namespace Laratel\Opentelemetry\Support;

/**
 * Remembers the previous reading of a cumulative OS counter (/proc/stat,
 * /proc/net/dev) so the next reading can be turned into a delta.
 *
 * Under PHP-FPM every request is a new process, so in-memory state is
 * always empty; readings are therefore kept in a small JSON file. The
 * read-and-replace happens under an exclusive lock, which hands each
 * interval to exactly one request — concurrent workers cannot report the
 * same delta twice.
 */
final class SnapshotStore
{
    /** In-process fallback when no state file is configured or writable. */
    private static array $memory = [];

    /**
     * Store $current under $key and return the reading it replaces
     * (an empty array when there is none yet).
     */
    public static function exchange(string $key, array $current): array
    {
        $path = config('opentelemetry.state_path');
        $handle = is_string($path) && $path !== '' ? @fopen($path, 'c+') : false;

        if ($handle === false) {
            return self::exchangeInMemory($key, $current);
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return self::exchangeInMemory($key, $current);
            }

            $state = json_decode((string) stream_get_contents($handle), true);
            $state = is_array($state) ? $state : [];
            $previous = is_array($state[$key] ?? null) ? $state[$key] : [];

            $state[$key] = $current;
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($state));
            fflush($handle);

            return $previous;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** Forget in-process readings (tests). */
    public static function reset(): void
    {
        self::$memory = [];
    }

    private static function exchangeInMemory(string $key, array $current): array
    {
        $previous = self::$memory[$key] ?? [];
        self::$memory[$key] = $current;

        return $previous;
    }
}
