<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry\Support;

use Dskripchenko\Telemetry\Recorder;

/**
 * The Server-Timing header (W3C): what the browser's network panel shows
 * under a request's timing — the application's total, its boot, the database,
 * Redis, outgoing HTTP and the application's own spans.
 */
final class ServerTiming
{
    public static function total(float $totalMs): string
    {
        return self::metric('app', $totalMs);
    }

    public static function full(Recorder $recorder, float $totalMs, float $bootMs): string
    {
        $metrics = [
            self::metric('app', $totalMs),
            self::metric('boot', $bootMs),
            self::metric('db', $recorder->queryMs(), 'db x'.$recorder->queries()),
        ];
        if ($recorder->commands() > 0) {
            $metrics[] = self::metric('cache', $recorder->commandMs(), 'redis x'.$recorder->commands());
        }
        if ($recorder->calls() > 0) {
            $metrics[] = self::metric('http', $recorder->callMs(), 'http x'.$recorder->calls());
        }
        foreach ($recorder->spans() as $name => $span) {
            $metrics[] = self::metric($name, $span['ms'], $span['n'] > 1 ? $name.' x'.$span['n'] : null);
        }

        return implode(', ', $metrics);
    }

    private static function metric(string $name, float $ms, ?string $description = null): string
    {
        return $name
            .($description === null ? '' : ';desc="'.$description.'"')
            .';dur='.number_format(max($ms, 0.0), 1, '.', '');
    }
}
