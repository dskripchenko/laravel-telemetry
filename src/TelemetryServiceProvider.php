<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry;

use Dskripchenko\Telemetry\Http\Middleware\MeasureRequest;
use Dskripchenko\Telemetry\Sinks\LogSink;
use Dskripchenko\Telemetry\Sinks\NullSink;
use Dskripchenko\Telemetry\Sinks\Sink;
use Dskripchenko\Telemetry\Sinks\StreamSink;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Log\LogManager;
use Illuminate\Support\ServiceProvider;

final class TelemetryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/telemetry.php', 'telemetry');

        // Scoped: a long-lived server (Octane) and a queue worker start each unit with a new one.
        $this->app->scoped(Recorder::class, static fn (): Recorder => new Recorder);

        $this->app->singleton(Sink::class, static function (Container $app): Sink {
            $config = $app->make(Repository::class);
            $sink = $config->get('telemetry.sink', 'stderr');
            $channel = $config->get('telemetry.log_channel');

            if (is_string($sink) && is_subclass_of($sink, Sink::class)) {
                return $app->make($sink);
            }

            return match ($sink) {
                'log' => new LogSink($app->make(LogManager::class), is_string($channel) && $channel !== '' ? $channel : null),
                'null', null, false => new NullSink,
                default => new StreamSink,
            };
        });

        $this->app->singleton(Reporter::class, static fn (Container $app): Reporter => new Reporter($app, $app->make(Repository::class)));
        $this->app->singleton(Subscriber::class, static fn (Container $app): Subscriber => new Subscriber($app, $app->make(Repository::class)));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/telemetry.php' => $this->app->configPath('telemetry.php')], 'telemetry-config');
        }

        if ($this->app->make(Repository::class)->get('telemetry.enabled') !== true) {
            return;
        }

        $this->app->make(Subscriber::class)->subscribe($this->app->make(Dispatcher::class));
        $this->countRedis();
        $this->measureRequests();
    }

    /** Redis tells of its commands only when asked to. */
    private function countRedis(): void
    {
        $enable = static function (mixed $redis): void {
            if (is_object($redis) && method_exists($redis, 'enableEvents')) {
                $redis->enableEvents();
            }
        };
        $this->app->afterResolving('redis', $enable);
        if ($this->app->resolved('redis')) {
            $enable($this->app->make('redis'));
        }
    }

    /** The middleware goes in front of the application's own, whenever the HTTP kernel comes to be. */
    private function measureRequests(): void
    {
        $prepend = static function (mixed $kernel): void {
            if (is_object($kernel) && method_exists($kernel, 'prependMiddleware')) {
                $kernel->prependMiddleware(MeasureRequest::class);
            }
        };
        $this->app->afterResolving(Kernel::class, $prepend);
        if ($this->app->resolved(Kernel::class)) {
            $prepend($this->app->make(Kernel::class));
        }
    }
}
