<?php

declare(strict_types=1);

use Dskripchenko\Telemetry\Tests\DisabledTestCase;
use Dskripchenko\Telemetry\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(DisabledTestCase::class)->in('Disabled');

/** Laravel keeps console events off in tests; this turns them on for the application at hand. */
function withConsoleEvents(): void
{
    $kernel = app(Kernel::class);
    $kernel->rerouteSymfonyCommandEvents();
    // The console application was built without them: the next one is built with.
    $kernel->setArtisan(null);
}
