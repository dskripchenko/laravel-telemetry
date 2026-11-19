<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry\Tests;

abstract class DisabledTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('telemetry.enabled', false);
    }
}
