<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry;

use Closure;
use Illuminate\Container\Container;

/**
 * Marks of the application's own: what a piece of work took, by name.
 *
 *     $pdf = Telemetry::measure('render', fn () => $renderer->render($document));
 *
 * The span is in the Server-Timing header of the request and in its log line
 * (`x_render_ms`), and in the line of a job or a command the same way.
 */
final class Telemetry
{
    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function measure(string $name, Closure $work): mixed
    {
        return self::recorder()->measure($name, $work);
    }

    /** A span measured elsewhere, in milliseconds. */
    public static function span(string $name, float $ms): void
    {
        self::recorder()->span($name, $ms);
    }

    public static function recorder(): Recorder
    {
        return Container::getInstance()->make(Recorder::class);
    }
}
