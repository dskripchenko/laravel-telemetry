<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('does nothing when off', function (): void {
    withConsoleEvents();
    $response = $this->get('/queries/3')->assertOk();
    Artisan::command('demo:work', function (): void {});
    $this->artisan('demo:work')->run();

    expect($response->headers->has('Server-Timing'))->toBeFalse()
        ->and($this->sink->events)->toBe([]);
});
