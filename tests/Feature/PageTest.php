<?php

declare(strict_types=1);
use Illuminate\Testing\TestResponse;

/** A beacon as the collector sends it: JSON in a text/plain body. */
function beacon(array $data, array $headers = ['Sec-Fetch-Site' => 'same-origin']): TestResponse
{
    $server = [];
    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('POST', '/telemetry/page', [], [], [], ['CONTENT_TYPE' => 'text/plain', ...$server], json_encode($data, JSON_THROW_ON_ERROR));
}

it('serves the collector with the address its beacon goes to', function (): void {
    $response = $this->get('/telemetry/page.js')->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('text/javascript; charset=utf-8')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=3600')
        ->and($response->getContent())->toContain("var ENDPOINT = '/telemetry/page'")->not->toContain('__ENDPOINT__');

    // The same script is not sent twice.
    $this->get('/telemetry/page.js', ['If-None-Match' => (string) $response->headers->get('ETag')])->assertStatus(304);
});

it('writes a page load as the browser saw it', function (): void {
    beacon([
        'page' => 'orders.list', 'nav' => 'navigate', 'proto' => 'h3',
        'start' => 1.2, 'dns' => 0, 'connect' => 180.5, 'tls' => 95.25, 'wait' => 515.12, 'server' => 48.5, 'download' => 5.8,
        'dom' => 890.4, 'load' => 1880.0, 'fcp' => 901.3, 'lcp' => 1210.0,
        'm_session' => 1100.5, 'm_ready' => 1700.0,
        'res_n' => 51, 'res_kb' => 19, 'api_n' => 12, 'api_ms' => 3400.2, 'api_max' => 520.1, 'api_server' => 300.0, 'api_end' => 1890.3,
        'rtt' => 250, 'down' => 1.45, 'net' => '4g',
    ])->assertNoContent();

    expect($this->sink->of('page'))->toHaveCount(1)
        ->and($this->sink->of('page')[0])->toBe([
            'event' => 'page', 'app' => 'shop', 'page' => 'orders.list', 'nav' => 'navigate', 'proto' => 'h3',
            'start' => 1.2, 'dns' => 0.0, 'connect' => 180.5, 'tls' => 95.3, 'wait' => 515.1, 'server' => 48.5, 'download' => 5.8,
            'dom' => 890.4, 'load' => 1880.0, 'fcp' => 901.3, 'lcp' => 1210.0,
            'api_ms' => 3400.2, 'api_max' => 520.1, 'api_server' => 300.0, 'api_end' => 1890.3,
            'res_n' => 51, 'res_kb' => 19, 'api_n' => 12, 'rtt' => 250,
            'down' => 1.45, 'net' => '4g',
            'm_session' => 1100.5, 'm_ready' => 1700.0,
        ])
        // The beacon itself is not a request to measure.
        ->and($this->sink->of('request'))->toBe([]);
});

it('keeps only what the collector sends: nothing of a caller\'s own text reaches the log', function (): void {
    beacon([
        'nav' => 'reload', 'page' => "orders\n{\"event\":\"request\"}", 'path' => '/orders/42/items/3f2b9c1e-8a6d-4f0e-9b1a-2c5d7e9f0a1b',
        'proto' => 'H2 <script>', 'wait' => -5, 'load' => 9e12, 'dom' => '12', 'net' => 'wifi-at-home',
        'event' => 'request', 'app' => 'other', 'note' => 'hello', 'm_ready' => 10, 'M_BAD' => 5, 'm_'.str_repeat('x', 40) => 5,
    ])->assertNoContent();

    expect($this->sink->of('page')[0])->toBe(['event' => 'page', 'app' => 'shop', 'page' => '/orders/{id}/items/{uuid}', 'nav' => 'reload', 'm_ready' => 10.0]);
});

it('drops what is not a page load of this site, without saying so', function (array $data, array $headers, string $body): void {
    $response = $body === ''
        ? beacon($data, $headers)
        : test()->call('POST', '/telemetry/page', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_SEC_FETCH_SITE' => 'same-origin'], $body);

    $response->assertNoContent();
    expect($this->sink->events)->toBe([]);
})->with([
    'no navigation' => [['page' => 'orders.list', 'load' => 100], ['Sec-Fetch-Site' => 'same-origin'], ''],
    'another site' => [['nav' => 'navigate'], ['Sec-Fetch-Site' => 'cross-site'], ''],
    'not JSON' => [[], [], 'nav=navigate'],
    'too long' => [[], [], '{"nav":"navigate","page":"'.str_repeat('a', 5000).'"}'],
]);

it('takes a beacon of a browser that does not say where it is from', function (): void {
    beacon(['nav' => 'navigate', 'page' => 'home'], [])->assertNoContent();

    expect($this->sink->of('page'))->toHaveCount(1);
});

it('limits the beacons of one address', function (): void {
    config(['telemetry.page.throttle' => 60]);
    foreach (range(1, 60) as $i) {
        beacon(['nav' => 'navigate', 'page' => 'home'])->assertNoContent();
    }

    beacon(['nav' => 'navigate', 'page' => 'home'])->assertStatus(429);
});
