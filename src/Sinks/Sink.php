<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry\Sinks;

/**
 * Where an event goes. An event is flat — names to scalars — so any log
 * storage can index its fields as they are.
 */
interface Sink
{
    /**
     * @param  array<string, bool|float|int|string|null>  $event
     */
    public function write(array $event): void;
}
