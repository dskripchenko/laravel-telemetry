<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry\Support;

/**
 * A statement as a log may keep it: its shape, never its values. Bound
 * parameters are placeholders already; this takes out what was written into
 * the text itself — strings, numbers, lists of any length.
 */
final class Sql
{
    private const int LENGTH = 600;

    public static function shape(string $sql): string
    {
        // Quoted strings ('it''s', 'a\'b'), then bare numbers, then lists and rows of placeholders.
        $shape = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/s", '?', $sql) ?? $sql;
        $shape = preg_replace('/(?<![A-Za-z0-9_$"`.])-?\d+(?:\.\d+)?(?![A-Za-z0-9_"`])/', '?', $shape) ?? $shape;
        $shape = preg_replace('/\$\d+/', '?', $shape) ?? $shape;
        $shape = preg_replace('/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?+)', $shape) ?? $shape;
        $shape = preg_replace('/\(\?\+\)(?:\s*,\s*\(\?\+\))+/', '(?+)+', $shape) ?? $shape;
        $shape = trim(preg_replace('/\s+/', ' ', $shape) ?? $shape);

        return mb_strlen($shape) > self::LENGTH ? mb_substr($shape, 0, self::LENGTH).'…' : $shape;
    }
}
