<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;

it('puts the total alone on a guest\'s answer and everything on a signed-in person\'s', function (): void {
    $guest = $this->get('/queries/2')->assertOk();
    expect($guest->headers->get('Server-Timing'))->toMatch('/^app;dur=\d+\.\d$/');

    $this->actingAs(new GenericUser(['id' => 1]));
    $person = $this->get('/queries/2')->assertOk();
    expect($person->headers->get('Server-Timing'))->toMatch('/^app;dur=\d+\.\d, boot;dur=\d+\.\d, db;desc="db x2";dur=\d+\.\d$/');
});

it('follows the configured mode of the header', function (string $mode, ?string $pattern): void {
    config(['telemetry.server_timing' => $mode]);
    $header = $this->get('/plain')->headers->get('Server-Timing');

    $pattern === null ? expect($header)->toBeNull() : expect($header)->toMatch($pattern);
})->with([
    ['full', '/^app;dur=[\d.]+, boot;dur=[\d.]+, db;desc="db x0";dur=0\.0$/'],
    ['total', '/^app;dur=[\d.]+$/'],
    ['off', null],
]);

it('writes a line per request once the answer has gone', function (): void {
    Context::add('request_id', 'req-12345678');
    $this->get('/queries/3')->assertOk();

    $lines = $this->sink->of('request');
    expect($lines)->toHaveCount(1)
        ->and($lines[0])->toMatchArray([
            'event' => 'request', 'app' => 'shop', 'request_id' => 'req-12345678',
            'route' => 'queries/{n}', 'method' => 'GET', 'status' => 200, 'auth' => false,
            'db_n' => 3, 'cache_n' => 0, 'http_n' => 0,
        ])
        ->and($lines[0]['ms'])->toBeFloat()->toBeGreaterThan(0.0)
        ->and($lines[0]['boot_ms'])->toBeFloat()
        ->and($lines[0]['db_ms'])->toBeFloat()
        ->and($lines[0]['mem_mb'])->toBeGreaterThan(0.0)
        ->and($this->sink->of('query'))->toBe([]);
});

it('names a request by its route: operations by name, objects by placeholder', function (string $path, string $route, int $status): void {
    $this->get($path)->assertStatus($status);

    expect($this->sink->of('request')[0]['route'])->toBe($route);
})->with([
    ['/api/v1/tasks/list', 'api/v1/tasks/list', 200],
    ['/items/DEMO-599', 'items/{item}', 200],
    ['/no/such/page', 'unmatched', 404],
]);

it('does not measure the probes', function (): void {
    $response = $this->get('/up')->assertOk();

    expect($response->headers->has('Server-Timing'))->toBeFalse()
        ->and($this->sink->events)->toBe([]);
});

it('writes only slow requests, or none, when told so', function (): void {
    config(['telemetry.requests' => 'slow', 'telemetry.slow_request_ms' => 60000]);
    $this->get('/plain')->assertOk();
    expect($this->sink->of('request'))->toBe([]);

    config(['telemetry.slow_request_ms' => 0]);
    $this->get('/plain')->assertOk();
    expect($this->sink->of('request'))->toHaveCount(1);

    config(['telemetry.requests' => 'off']);
    $this->get('/plain')->assertOk();
    expect($this->sink->of('request'))->toHaveCount(1);
});

it('gives a statement repeated many times a line of its own — an N+1', function (): void {
    config(['telemetry.repeated_queries' => 5]);
    $this->get('/queries/12')->assertOk();

    $queries = $this->sink->of('query');
    expect($queries)->toHaveCount(1)
        ->and($queries[0])->toMatchArray(['event' => 'query', 'app' => 'shop', 'in' => 'request', 'unit' => 'queries/{n}', 'kind' => 'repeated', 'sql' => 'select ? as x', 'n' => 12]);
});

it('gives a slow statement a line, its shape without its values', function (): void {
    $this->get('/slow-query')->assertOk();

    expect($this->sink->of('query')[0])->toMatchArray([
        'kind' => 'slow', 'unit' => 'slow-query', 'n' => 1, 'ms' => 150.0, 'max_ms' => 150.0,
        'sql' => 'select * from "orders" where "total" > ? and "state" = ?',
    ]);
});

it('counts outgoing calls, and gives a slow one a line without its identifiers', function (): void {
    Http::fake(['*' => Http::response(['ok' => true])]);
    $this->get('/call')->assertOk();
    expect($this->sink->of('request')[0])->toMatchArray(['http_n' => 1])
        ->and($this->sink->of('call'))->toBe([]);

    // Any call is slow against a threshold it cannot be under.
    $this->sink->events = [];
    config(['telemetry.slow_call_ms' => 1]);
    Http::fake(['*' => static function () {
        usleep(3000);

        return Http::response(['ok' => true]);
    }]);
    $this->get('/call')->assertOk();

    expect($this->sink->of('call'))->toHaveCount(1)
        ->and($this->sink->of('call')[0])->toMatchArray(['in' => 'request', 'unit' => 'call', 'method' => 'GET', 'host' => 'tracker.example.test', 'path' => '/api/v1/tasks/list', 'status' => 200]);
});

it('shows the application\'s own marks in the header and in the line', function (): void {
    config(['telemetry.server_timing' => 'full']);
    $response = $this->get('/render')->assertOk();

    expect($response->headers->get('Server-Timing'))->toMatch('/, render;dur=[\d.]+$/')
        ->and($this->sink->of('request')[0])->toHaveKey('x_render_ms');
});
