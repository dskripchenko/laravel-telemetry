<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;

/**
 * Feeds the recorder from the framework's own events and reports the units
 * that are not requests: a queued job, a console command.
 */
final class Subscriber
{
    /** The job in work has its line already (an exception, then the failure). */
    private bool $jobReported = true;

    /** Commands inside commands: only the outermost is a unit. */
    private int $commandDepth = 0;

    public function __construct(private readonly Container $container, private readonly Repository $config) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(QueryExecuted::class, function (QueryExecuted $event): void {
            $this->recorder()->query($event->sql, (float) $event->time);
        });

        // Illuminate\Redis is optional: its event is named, not imported.
        $events->listen('Illuminate\Redis\Events\CommandExecuted', function (object $event): void {
            $time = property_exists($event, 'time') ? $event->time : null;
            $this->recorder()->command(is_numeric($time) ? (float) $time : 0.0);
        });

        $events->listen(RequestSending::class, function (RequestSending $event): void {
            $this->recorder()->callStarted($event->request);
        });
        $events->listen(ResponseReceived::class, function (ResponseReceived $event): void {
            $this->recorder()->callFinished($event->request, $event->request->method(), $event->request->url(), $event->response->status());
        });
        $events->listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
            $this->recorder()->callFinished($event->request, $event->request->method(), $event->request->url(), null);
        });

        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            if ($this->inline($event->connectionName)) {
                return;
            }
            // A worker lives through many jobs: each starts from nothing.
            $this->recorder()->reset();
            memory_reset_peak_usage();
            $this->jobReported = false;
        });
        $events->listen(JobProcessed::class, function (JobProcessed $event): void {
            $this->job($event->connectionName, $event->job, 'ok');
        });
        $events->listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event): void {
            $this->job($event->connectionName, $event->job, 'failed');
        });
        $events->listen(JobFailed::class, function (JobFailed $event): void {
            $this->job($event->connectionName, $event->job, 'failed');
        });

        $events->listen(CommandStarting::class, function (): void {
            $this->commandDepth++;
        });
        $events->listen(CommandFinished::class, function (CommandFinished $event): void {
            $this->commandDepth = max($this->commandDepth - 1, 0);
            if ($this->commandDepth === 0) {
                $this->command($event);
            }
        });
    }

    private function job(string $connection, Job $job, string $status): void
    {
        if ($this->inline($connection) || $this->jobReported) {
            return;
        }
        $this->jobReported = true;

        $recorder = $this->recorder();
        $name = $job->resolveName();
        $this->container->make(Reporter::class)->unit('job', $name, [
            'job' => $name,
            'queue' => $job->getQueue(),
            'status' => $status,
            'attempt' => $job->attempts(),
        ], $recorder, $recorder->elapsed());
    }

    /** A console command is its process: what the process did since it began. */
    private function command(CommandFinished $event): void
    {
        $name = $event->command;
        if ($name === '' || $this->ignoredCommand($name)) {
            return;
        }

        $recorder = $this->recorder();
        $began = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        $recorder->startedAt(is_numeric($began) ? (float) $began : null);
        $this->container->make(Reporter::class)->unit('command', $name, [
            'command' => $name,
            'status' => $event->exitCode === 0 ? 'ok' : 'failed',
        ], $recorder, $recorder->elapsed());
    }

    /** A job run inside the unit that dispatched it (the sync driver) is that unit's work. */
    private function inline(string $connection): bool
    {
        return $connection === 'sync';
    }

    private function ignoredCommand(string $name): bool
    {
        $ignored = $this->config->get('telemetry.ignore_commands', []);

        return is_array($ignored) && in_array($name, $ignored, true);
    }

    private function recorder(): Recorder
    {
        return $this->container->make(Recorder::class);
    }
}
