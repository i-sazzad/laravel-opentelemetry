<?php

namespace Laratel\Opentelemetry\Support;

final class Sql
{
    /** Keywords allowed as a metric label / span name (keeps cardinality bounded). */
    private const OPERATIONS = [
        'select', 'insert', 'update', 'delete', 'replace', 'upsert', 'merge', 'with',
        'create', 'alter', 'drop', 'truncate', 'rename',
        'begin', 'start', 'commit', 'rollback', 'savepoint', 'release',
        'set', 'show', 'describe', 'explain', 'pragma', 'call', 'exec', 'execute',
        'lock', 'unlock', 'grant', 'revoke', 'analyze', 'vacuum',
    ];

    /**
     * The leading SQL keyword of a statement, lower-cased, or "other" when
     * it is not a recognised operation (comments, vendor-specific syntax).
     */
    public static function operation(string $sql): string
    {
        $keyword = strtolower((string) strtok(ltrim($sql), " \t\r\n(;"));

        return in_array($keyword, self::OPERATIONS, true) ? $keyword : 'other';
    }
}
