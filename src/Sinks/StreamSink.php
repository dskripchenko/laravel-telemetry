<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry\Sinks;

/**
 * One JSON object per line on a stream — stderr by default, which is a
 * container's log. A collector (Vector, Fluent Bit, Promtail) takes the lines
 * as they are; nothing is kept in the application.
 */
final class StreamSink implements Sink
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $stream = 'php://stderr') {}

    public function write(array $event): void
    {
        $line = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
        if ($line === false) {
            return;
        }
        if ($this->handle === null) {
            $handle = @fopen($this->stream, 'ab');
            if ($handle === false) {
                return;
            }
            $this->handle = $handle;
        }
        // A line is written whole or not at all: telemetry never fails the work it measures.
        @fwrite($this->handle, $line."\n");
    }
}
