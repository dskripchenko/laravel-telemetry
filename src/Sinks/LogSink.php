<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry\Sinks;

use Illuminate\Log\LogManager;
use Throwable;

/**
 * A channel of config/logging.php: the event's name is the message, its
 * fields are the context. For applications whose logs already go through a
 * formatter of their own.
 */
final class LogSink implements Sink
{
    public function __construct(private readonly LogManager $logs, private readonly ?string $channel = null) {}

    public function write(array $event): void
    {
        try {
            $name = is_string($event['event'] ?? null) ? $event['event'] : 'telemetry';
            $this->logs->channel($this->channel)->info('telemetry.'.$name, $event);
        } catch (Throwable) {
            // Telemetry never fails the work it measures.
        }
    }
}
