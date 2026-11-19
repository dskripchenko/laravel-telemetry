<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry\Sinks;

/** Nowhere: the Server-Timing header alone. */
final class NullSink implements Sink
{
    public function write(array $event): void {}
}
