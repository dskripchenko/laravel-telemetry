<?php

declare(strict_types=1);

use Dskripchenko\Telemetry\Recorder;
use Dskripchenko\Telemetry\Tests\Support\FakeJob;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function queued(string $connection = 'redis'): FakeJob
{
    return new FakeJob($connection, 'mail', 'App\\Jobs\\SendDigest');
}

it('writes a line per job of a worker, with what the job alone did', function (): void {
    DB::select('select 1');
    $job = queued();

    event(new JobProcessing('redis', $job));
    DB::select('select 2');
    DB::select('select 3');
    event(new JobProcessed('redis', $job));

    $lines = $this->sink->of('job');
    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toMatchArray(['event' => 'job', 'app' => 'shop', 'job' => 'App\\Jobs\\SendDigest', 'queue' => 'mail', 'status' => 'ok', 'attempt' => 1, 'db_n' => 2])
        ->and($lines[0]['ms'])->toBeFloat();
});

it('reports a failed job once: the exception, then the failure', function (): void {
    $job = queued();
    $error = new RuntimeException('no');

    event(new JobProcessing('redis', $job));
    event(new JobExceptionOccurred('redis', $job, $error));
    event(new JobFailed('redis', $job, $error));

    expect($this->sink->of('job'))->toHaveCount(1)
        ->and($this->sink->of('job')[0]['status'])->toBe('failed');
});

it('leaves a job run inside a request to the request', function (): void {
    $job = queued('sync');
    DB::select('select 1');

    event(new JobProcessing('sync', $job));
    event(new JobProcessed('sync', $job));

    expect($this->sink->of('job'))->toBe([])
        ->and(app(Recorder::class)->queries())->toBe(1);
});

it('writes a line per console command, none for the ones that only start others', function (): void {
    withConsoleEvents();
    Artisan::command('demo:work', function (): void {
        DB::select('select 1');
    });
    Artisan::command('demo:wrapper', function (): void {
        Artisan::call('demo:work');
    });

    $this->artisan('demo:wrapper')->run();
    $lines = $this->sink->of('command');
    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toMatchArray(['event' => 'command', 'command' => 'demo:wrapper', 'status' => 'ok', 'db_n' => 1]);

    config(['telemetry.ignore_commands' => ['demo:work']]);
    $this->sink->events = [];
    $this->artisan('demo:work')->run();
    expect($this->sink->of('command'))->toBe([]);
});
