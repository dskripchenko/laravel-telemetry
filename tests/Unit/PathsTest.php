<?php

declare(strict_types=1);

use Dskripchenko\Telemetry\Support\Paths;

it('keeps a path\'s words and none of its identifiers', function (string $path, string $masked): void {
    expect(Paths::mask($path))->toBe($masked);
})->with([
    ['/api/v1/tasks/list', '/api/v1/tasks/list'],
    ['/api/v4/projects/42/merge_requests/7', '/api/v4/projects/{id}/merge_requests/{id}'],
    ['/realms/forge/users/3f2b9c1e-8a6d-4f0e-9b1a-2c5d7e9f0a1b', '/realms/forge/users/{uuid}'],
    ['/api/push/Zk3vQ9pLx7Tn2Rb5Yw8Hc1Md', '/api/push/{token}'],
    ['/spa/assets/a-very-long-file-name-of-a-bundle.js', '/spa/assets/a-very-long-file-name-of-a-bundle.js'],
    ['/search?q=ada+lovelace', '/search'],
]);
