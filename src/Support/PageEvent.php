<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry\Support;

/**
 * What a browser said about a page load, made safe to log: only the fields
 * the collector sends, each of its own type and within its bounds. Anything
 * else a caller adds is dropped, so the endpoint cannot be used to write text
 * of one's choosing into the log.
 */
final class PageEvent
{
    /** A page load longer than this is not a measurement any more. */
    private const float MAX_MS = 600000.0;

    private const array DURATIONS = [
        'start', 'dns', 'connect', 'tls', 'wait', 'server', 'download', 'dom', 'load', 'fcp', 'lcp',
        'api_ms', 'api_max', 'api_server', 'api_end',
    ];

    private const array COUNTS = ['res_n' => 100000, 'res_kb' => 10000000, 'api_n' => 100000, 'rtt' => 60000];

    private const array NAVIGATIONS = ['navigate', 'reload', 'back_forward', 'prerender'];

    private const array NETWORKS = ['slow-2g', '2g', '3g', '4g'];

    /** Marks of the application's own (m_ready, m_session, …) taken from one beacon. */
    private const int MARKS = 8;

    /**
     * @param  array<mixed>  $sent
     * @return array<string, bool|float|int|string|null>|null Null — not a page load at all
     */
    public static function from(array $sent): ?array
    {
        $navigation = $sent['nav'] ?? null;
        if (! is_string($navigation) || ! in_array($navigation, self::NAVIGATIONS, true)) {
            return null;
        }

        $event = ['page' => self::page($sent), 'nav' => $navigation];

        $protocol = $sent['proto'] ?? null;
        if (is_string($protocol) && preg_match('/^[a-z0-9\/.-]{1,16}$/', $protocol) === 1) {
            $event['proto'] = $protocol;
        }
        foreach (self::DURATIONS as $name) {
            $value = self::number($sent[$name] ?? null, self::MAX_MS);
            if ($value !== null) {
                $event[$name] = round($value, 1);
            }
        }
        foreach (self::COUNTS as $name => $max) {
            $value = self::number($sent[$name] ?? null, (float) $max);
            if ($value !== null) {
                $event[$name] = (int) round($value);
            }
        }
        $down = self::number($sent['down'] ?? null, 100000.0);
        if ($down !== null) {
            $event['down'] = round($down, 2);
        }
        $network = $sent['net'] ?? null;
        if (is_string($network) && in_array($network, self::NETWORKS, true)) {
            $event['net'] = $network;
        }

        $marks = 0;
        foreach ($sent as $name => $value) {
            if (is_string($name) && preg_match('/^m_[a-z0-9_]{1,24}$/', $name) === 1 && $marks < self::MARKS) {
                $at = self::number($value, self::MAX_MS);
                if ($at !== null) {
                    $event[$name] = round($at, 1);
                    $marks++;
                }
            }
        }

        return $event;
    }

    /**
     * The page's name: the one the application gave its mark, else the path
     * with its identifiers masked.
     *
     * @param  array<mixed>  $sent
     */
    private static function page(array $sent): string
    {
        $page = $sent['page'] ?? null;
        if (is_string($page) && preg_match('/^[A-Za-z0-9_.:\/{}-]{1,80}$/', $page) === 1) {
            return $page;
        }
        $path = $sent['path'] ?? null;
        if (is_string($path) && $path !== '' && preg_match('/^\/[A-Za-z0-9_.~%\/-]{0,300}$/', $path) === 1) {
            return Paths::mask($path);
        }

        return 'unknown';
    }

    private static function number(mixed $value, float $max): ?float
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) && $number >= 0.0 && $number <= $max ? $number : null;
    }
}
