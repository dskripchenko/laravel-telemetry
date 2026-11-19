<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry\Testing;

use Dskripchenko\Telemetry\Sinks\Sink;
use Illuminate\Contracts\Container\Container;

/**
 * Keeps the events for a test to read:
 *
 *     $sink = MemorySink::install($this->app);
 *     ...
 *     expect($sink->of('request'))->toHaveCount(1);
 */
final class MemorySink implements Sink
{
    /** @var list<array<string, bool|float|int|string|null>> */
    public array $events = [];

    public static function install(Container $container): self
    {
        $sink = new self;
        $container->instance(Sink::class, $sink);

        return $sink;
    }

    public function write(array $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @return list<array<string, bool|float|int|string|null>>
     */
    public function of(string $event): array
    {
        return array_values(array_filter($this->events, static fn (array $e): bool => ($e['event'] ?? null) === $event));
    }
}
