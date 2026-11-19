<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry\Tests\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

/** A job as a worker's events carry it: a name, a queue, an attempt. */
final class FakeJob extends Job implements JobContract
{
    public function __construct(string $connection, string $queue, private readonly string $name)
    {
        $this->container = Container::getInstance();
        $this->connectionName = $connection;
        $this->queue = $queue;
    }

    public function getJobId(): string
    {
        return 'job-1';
    }

    public function getRawBody(): string
    {
        return json_encode(['uuid' => 'job-1', 'displayName' => $this->name, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'data' => []], JSON_THROW_ON_ERROR);
    }

    public function attempts(): int
    {
        return 1;
    }
}
