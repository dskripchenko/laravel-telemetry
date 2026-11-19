<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry\Support;

/**
 * A path as a log may keep it: segments that name an object — a number, a
 * UUID, a long token — become placeholders, and the query is never part of it.
 */
final class Paths
{
    private const int LENGTH = 160;

    public static function mask(string $path): string
    {
        $path = explode('?', $path, 2)[0];
        $segments = array_map(static function (string $segment): string {
            if ($segment === '') {
                return $segment;
            }
            if (preg_match('/^\d+$/', $segment) === 1) {
                return '{id}';
            }
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment) === 1) {
                return '{uuid}';
            }
            // Long and without a dot (a file keeps its name): a token, a hash, a key.
            if (strlen($segment) >= 20 && ! str_contains($segment, '.')) {
                return '{token}';
            }

            return $segment;
        }, explode('/', $path));
        $masked = implode('/', $segments);

        return strlen($masked) > self::LENGTH ? substr($masked, 0, self::LENGTH) : $masked;
    }
}
