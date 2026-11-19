<?php

declare(strict_types=1);

use Dskripchenko\Telemetry\Sinks\LogSink;
use Dskripchenko\Telemetry\Sinks\NullSink;
use Dskripchenko\Telemetry\Sinks\Sink;
use Dskripchenko\Telemetry\Sinks\StreamSink;
use Illuminate\Log\LogManager;

it('writes an event as one JSON line', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'telemetry');
    $sink = new StreamSink((string) $file);

    $sink->write(['event' => 'request', 'route' => 'api/v1/tasks/list', 'ms' => 41.0, 'note' => 'Привет']);
    $sink->write(['event' => 'job', 'ms' => 3.5]);

    expect(file((string) $file, FILE_IGNORE_NEW_LINES))->toBe([
        '{"event":"request","route":"api/v1/tasks/list","ms":41.0,"note":"Привет"}',
        '{"event":"job","ms":3.5}',
    ]);
    unlink((string) $file);
});

it('writes to a log channel when asked to', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'telemetry');
    config(['logging.channels.telemetry' => ['driver' => 'single', 'path' => $file]]);

    (new LogSink(app(LogManager::class), 'telemetry'))->write(['event' => 'request', 'route' => 'plain', 'ms' => 1.5]);

    expect((string) file_get_contents((string) $file))->toContain('telemetry.request')->toContain('"route":"plain"');
    unlink((string) $file);
});

it('picks the sink the configuration names', function (mixed $configured, string $class): void {
    config(['telemetry.sink' => $configured]);
    app()->forgetInstance(Sink::class);

    expect(app(Sink::class))->toBeInstanceOf($class);
})->with([
    ['stderr', StreamSink::class],
    ['log', LogSink::class],
    ['null', NullSink::class],
    [NullSink::class, NullSink::class],
]);
