<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry;

use Closure;

/**
 * What one unit of work — a request, a job, a command — has done so far:
 * queries, Redis commands, outgoing HTTP calls and the spans the application
 * marked itself. Listeners feed it; the middleware and the listeners of jobs
 * and commands read it when the unit ends.
 */
final class Recorder
{
    /** Statements remembered per unit: a long job must not grow without end. */
    private const int STATEMENTS = 300;

    /** Slow calls remembered per unit. */
    private const int CALLS = 20;

    private float $startedAt;

    private int $queries = 0;

    private float $queryMs = 0.0;

    /** @var array<string, array{sql: string, n: int, ms: float, max: float}> */
    private array $statements = [];

    private int $commands = 0;

    private float $commandMs = 0.0;

    private int $calls = 0;

    private float $callMs = 0.0;

    /** @var array<int, float> */
    private array $openCalls = [];

    /** @var list<array{method: string, host: string, path: string, status: int|null, ms: float}> */
    private array $slowCalls = [];

    /** @var array<string, array{n: int, ms: float}> */
    private array $spans = [];

    /** A request has been measured with this object already. */
    private bool $served = false;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    /**
     * A request begins. The first one of a process keeps what was recorded
     * before the middleware (queries of the application's boot); a process that
     * serves many starts each of the next ones from nothing.
     */
    public function request(): void
    {
        if ($this->served) {
            $this->reset();
        }
        $this->served = true;
    }

    /** A new unit of work starts: a job of a worker, a command. */
    public function reset(?float $startedAt = null): void
    {
        $this->startedAt = $startedAt ?? microtime(true);
        $this->queries = 0;
        $this->queryMs = 0.0;
        $this->statements = [];
        $this->commands = 0;
        $this->commandMs = 0.0;
        $this->calls = 0;
        $this->callMs = 0.0;
        $this->openCalls = [];
        $this->slowCalls = [];
        $this->spans = [];
    }

    /** The unit began earlier than this object was made (the request's first byte). */
    public function startedAt(?float $at = null): float
    {
        if ($at !== null && $at > 0.0 && $at < $this->startedAt) {
            $this->startedAt = $at;
        }

        return $this->startedAt;
    }

    public function query(string $sql, float $ms): void
    {
        $this->queries++;
        $this->queryMs += $ms;

        $known = $this->statements[$sql] ?? null;
        if ($known !== null) {
            $known['n']++;
            $known['ms'] += $ms;
            $known['max'] = max($known['max'], $ms);
            $this->statements[$sql] = $known;
        } elseif (count($this->statements) < self::STATEMENTS) {
            $this->statements[$sql] = ['sql' => $sql, 'n' => 1, 'ms' => $ms, 'max' => $ms];
        }
    }

    /** A Redis command: cache, session, queue. */
    public function command(float $ms): void
    {
        $this->commands++;
        $this->commandMs += $ms;
    }

    /** An outgoing HTTP call leaves; the same request object comes back in callFinished(). */
    public function callStarted(object $request): void
    {
        $this->openCalls[spl_object_id($request)] = microtime(true);
    }

    public function callFinished(object $request, string $method, string $url, ?int $status, ?float $transferMs = null): void
    {
        $id = spl_object_id($request);
        $started = $this->openCalls[$id] ?? null;
        unset($this->openCalls[$id]);
        $ms = $transferMs ?? ($started === null ? 0.0 : (microtime(true) - $started) * 1000);

        $this->calls++;
        $this->callMs += $ms;
        if (count($this->slowCalls) < self::CALLS) {
            $parts = parse_url($url);
            $this->slowCalls[] = [
                'method' => strtoupper($method),
                'host' => is_array($parts) && isset($parts['host']) ? $parts['host'] : '',
                'path' => is_array($parts) && isset($parts['path']) ? $parts['path'] : '/',
                'status' => $status,
                'ms' => $ms,
            ];
        }
    }

    /**
     * A span the application marks itself: its time is in the header and in the
     * unit's line under the name.
     *
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public function measure(string $name, Closure $work): mixed
    {
        $started = microtime(true);
        try {
            return $work();
        } finally {
            $this->span($name, (microtime(true) - $started) * 1000);
        }
    }

    public function span(string $name, float $ms): void
    {
        $name = self::spanName($name);
        if ($name === '') {
            return;
        }
        $known = $this->spans[$name] ?? ['n' => 0, 'ms' => 0.0];
        $this->spans[$name] = ['n' => $known['n'] + 1, 'ms' => $known['ms'] + $ms];
    }

    /** Milliseconds since the unit began. */
    public function elapsed(): float
    {
        return (microtime(true) - $this->startedAt) * 1000;
    }

    public function queries(): int
    {
        return $this->queries;
    }

    public function queryMs(): float
    {
        return $this->queryMs;
    }

    public function commands(): int
    {
        return $this->commands;
    }

    public function commandMs(): float
    {
        return $this->commandMs;
    }

    public function calls(): int
    {
        return $this->calls;
    }

    public function callMs(): float
    {
        return $this->callMs;
    }

    /** @return array<string, array{n: int, ms: float}> */
    public function spans(): array
    {
        return $this->spans;
    }

    /** @return list<array{sql: string, n: int, ms: float, max: float}> */
    public function statements(): array
    {
        return array_values($this->statements);
    }

    /** @return list<array{method: string, host: string, path: string, status: int|null, ms: float}> */
    public function outgoing(): array
    {
        return $this->slowCalls;
    }

    /** A span's name as a header and a log field take it: a-z, 0-9, _ and -, up to 32. */
    private static function spanName(string $name): string
    {
        $clean = preg_replace('/[^a-z0-9_-]+/', '_', strtolower($name));

        return substr(trim(is_string($clean) ? $clean : '', '_'), 0, 32);
    }
}
