<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry;

use Dskripchenko\Telemetry\Sinks\Sink;
use Dskripchenko\Telemetry\Support\Paths;
use Dskripchenko\Telemetry\Support\Sql;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Log\Context\Repository as Context;
use Throwable;

/**
 * Turns what a unit of work did into events: the unit's own line (request,
 * job, command) and a line for each query and outgoing call worth one. Every
 * event is flat and carries no values of the data — a statement's shape, a
 * path without identifiers.
 */
final class Reporter
{
    public function __construct(private readonly Container $container, private readonly Repository $config) {}

    /**
     * The unit's line, then the lines of its heavy queries and slow calls.
     *
     * @param  'request'|'job'|'command'  $event
     * @param  string  $unit  What the unit is counted under: a route, a job's class, a command's name
     * @param  array<string, bool|float|int|string|null>  $fields
     */
    public function unit(string $event, string $unit, array $fields, Recorder $recorder, float $ms, bool $line = true): void
    {
        try {
            $common = $this->common();
            if ($line) {
                $this->sink()->write([
                    'event' => $event,
                    ...$common,
                    ...$fields,
                    'ms' => self::round($ms),
                    ...$this->work($recorder),
                ]);
            }
            foreach ($this->queries($recorder) as $query) {
                $this->sink()->write(['event' => 'query', ...$common, 'in' => $event, 'unit' => $unit, ...$query]);
            }
            foreach ($this->calls($recorder) as $call) {
                $this->sink()->write(['event' => 'call', ...$common, 'in' => $event, 'unit' => $unit, ...$call]);
            }
        } catch (Throwable) {
            // Telemetry never fails the work it measures.
        }
    }

    /**
     * @return array<string, bool|float|int|string|null>
     */
    private function common(): array
    {
        $common = ['app' => $this->string('telemetry.app', 'app')];
        if ($this->container->bound(Context::class)) {
            $id = $this->container->make(Context::class)->get('request_id');
            if (is_string($id) && $id !== '') {
                $common['request_id'] = $id;
            }
        }

        return $common;
    }

    /**
     * @return array<string, float|int>
     */
    private function work(Recorder $recorder): array
    {
        $work = [
            'db_n' => $recorder->queries(),
            'db_ms' => self::round($recorder->queryMs()),
            'cache_n' => $recorder->commands(),
            'cache_ms' => self::round($recorder->commandMs()),
            'http_n' => $recorder->calls(),
            'http_ms' => self::round($recorder->callMs()),
            'mem_mb' => self::round(memory_get_peak_usage(true) / 1048576),
        ];
        foreach ($recorder->spans() as $name => $span) {
            $work['x_'.$name.'_ms'] = self::round($span['ms']);
        }

        return $work;
    }

    /**
     * Slow and repeated statements, the heaviest first.
     *
     * @return list<array<string, float|int|string>>
     */
    private function queries(Recorder $recorder): array
    {
        $slow = $this->int('telemetry.slow_query_ms', 100);
        $repeated = $this->int('telemetry.repeated_queries', 8);
        $limit = $this->int('telemetry.query_events', 5);
        if ($limit <= 0) {
            return [];
        }

        $found = [];
        foreach ($recorder->statements() as $statement) {
            $kind = match (true) {
                $repeated > 0 && $statement['n'] >= $repeated => 'repeated',
                $slow > 0 && $statement['max'] >= $slow => 'slow',
                default => null,
            };
            if ($kind !== null) {
                $found[] = [
                    'kind' => $kind,
                    'sql' => Sql::shape($statement['sql']),
                    'n' => $statement['n'],
                    'ms' => self::round($statement['ms']),
                    'max_ms' => self::round($statement['max']),
                ];
            }
        }
        usort($found, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        return array_slice($found, 0, $limit);
    }

    /**
     * Outgoing calls at or over the threshold.
     *
     * @return list<array<string, float|int|string|null>>
     */
    private function calls(Recorder $recorder): array
    {
        $slow = $this->int('telemetry.slow_call_ms', 300);
        if ($slow <= 0) {
            return [];
        }

        $found = [];
        foreach ($recorder->outgoing() as $call) {
            if ($call['ms'] >= $slow) {
                $found[] = [
                    'method' => $call['method'],
                    'host' => $call['host'],
                    'path' => Paths::mask($call['path']),
                    'status' => $call['status'],
                    'ms' => self::round($call['ms']),
                ];
            }
        }

        return $found;
    }

    private function sink(): Sink
    {
        return $this->container->make(Sink::class);
    }

    private function int(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function string(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private static function round(float $value): float
    {
        return round($value, 1);
    }
}
