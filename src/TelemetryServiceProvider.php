<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry;

use Dskripchenko\Telemetry\Http\Controllers\PageController;
use Dskripchenko\Telemetry\Http\Middleware\MeasureRequest;
use Dskripchenko\Telemetry\Sinks\LogSink;
use Dskripchenko\Telemetry\Sinks\NullSink;
use Dskripchenko\Telemetry\Sinks\Sink;
use Dskripchenko\Telemetry\Sinks\StreamSink;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Log\LogManager;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
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
        $this->takePages();
    }

    /** The collector script and its beacon: two routes outside any group — no session, no CSRF. */
    private function takePages(): void
    {
        $config = $this->app->make(Repository::class);
        // Cached routes hold these two already: they were here when the cache was made.
        if ($config->get('telemetry.page.enabled') !== true || ($this->app instanceof CachesRoutes && $this->app->routesAreCached())) {
            return;
        }
        $path = $config->get('telemetry.page.path', 'telemetry');
        $path = trim(is_string($path) && $path !== '' ? $path : 'telemetry', '/');
        $throttle = $config->get('telemetry.page.throttle', 60);
        $perMinute = is_numeric($throttle) && (int) $throttle > 0 ? (int) $throttle : 60;

        $router = $this->app->make(Router::class);
        $router->get($path.'/page.js', [PageController::class, 'script'])->name('telemetry.script');
        $router->post($path.'/page', [PageController::class, 'store'])
            ->middleware(ThrottleRequests::class.':'.$perMinute.',1')
            ->name('telemetry.page');
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
