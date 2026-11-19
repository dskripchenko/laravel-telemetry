<?php

declare(strict_types=1);

use Dskripchenko\Telemetry\Recorder;
use Dskripchenko\Telemetry\Support\ServerTiming;

it('names the total alone', function (): void {
    expect(ServerTiming::total(41.26))->toBe('app;dur=41.3');
});

it('lists the boot, the database and only what was used beyond them', function (): void {
    $recorder = new Recorder;
    $recorder->query('select 1', 3.2);
    $recorder->query('select 1', 4.1);

    expect(ServerTiming::full($recorder, 41.26, 8.04))->toBe('app;dur=41.3, boot;dur=8.0, db;desc="db x2";dur=7.3');

    $recorder->command(0.4);
    $recorder->span('Render PDF', 12.0);
    $recorder->span('Render PDF', 5.5);

    expect(ServerTiming::full($recorder, 60.0, 8.0))
        ->toBe('app;dur=60.0, boot;dur=8.0, db;desc="db x2";dur=7.3, cache;desc="redis x1";dur=0.4, render_pdf;desc="render_pdf x2";dur=17.5');
});
