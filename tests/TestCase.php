<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry\Tests;

use Dskripchenko\Telemetry\Telemetry;
use Dskripchenko\Telemetry\TelemetryServiceProvider;
use Dskripchenko\Telemetry\Testing\MemorySink;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Base;

abstract class TestCase extends Base
{
    protected MemorySink $sink;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sink = MemorySink::install($this->app);
    }

    protected function getPackageProviders($app): array
    {
        return [TelemetryServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('telemetry.enabled', true);
        $app['config']->set('telemetry.app', 'shop');
    }

    protected function defineRoutes($router): void
    {
        $router->get('plain', static fn (): string => 'ok');
        $router->get('up', static fn (): string => 'ok');
        $router->get('api/{version}/{controller}/{action}', static fn (): array => ['success' => true]);
        $router->get('items/{item}', static fn (): string => 'item');
        $router->get('queries/{n}', static function (string $n): string {
            for ($i = 0; $i < (int) $n; $i++) {
                DB::select('select ? as x', [$i]);
            }

            return 'done';
        });
        $router->get('slow-query', static function (): string {
            event(new QueryExecuted('select * from "orders" where "total" > 1000 and "state" = \'open\'', [], 150.0, DB::connection()));

            return 'done';
        });
        $router->get('render', static fn (): string => Telemetry::measure('render', static fn (): string => 'rendered'));
        $router->get('call', static function (): string {
            Http::get('https://tracker.example.test/api/v1/tasks/list?assignee=7');

            return 'called';
        });
    }
}
