<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry\Http\Middleware;

use Closure;
use Dskripchenko\Telemetry\Recorder;
use Dskripchenko\Telemetry\Reporter;
use Dskripchenko\Telemetry\Support\RouteName;
use Dskripchenko\Telemetry\Support\ServerTiming;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The outermost middleware: times the request from its first byte, puts the
 * Server-Timing header on the answer and, once the answer has gone, writes the
 * request's line. The service provider registers it; nothing is to be added to
 * the application's own list.
 */
final class MeasureRequest
{
    private const string MEASURED = 'telemetry.measured';

    public function __construct(private readonly Container $container, private readonly Repository $config) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->ignored($request)) {
            return $next($request);
        }

        $recorder = $this->container->make(Recorder::class);
        $recorder->request();
        $firstByte = $request->server('REQUEST_TIME_FLOAT');
        $started = $recorder->startedAt(is_numeric($firstByte) ? (float) $firstByte : null);
        $entered = microtime(true);

        $response = $next($request);

        $total = (microtime(true) - $started) * 1000;
        $boot = max(($entered - $started) * 1000, 0.0);
        $signedIn = $this->signedIn();
        $request->attributes->set(self::MEASURED, ['ms' => $total, 'boot_ms' => $boot, 'auth' => $signedIn]);

        if ($response instanceof Response) {
            $header = $this->header($recorder, $total, $boot, $signedIn);
            if ($header !== null) {
                $response->headers->set('Server-Timing', $header, false);
            }
        }

        return $response;
    }

    /** After the answer has gone: the line costs the person nothing. */
    public function terminate(Request $request, Response $response): void
    {
        $measured = $request->attributes->get(self::MEASURED);
        if (! is_array($measured) || ! is_float($measured['ms'] ?? null) || ! is_float($measured['boot_ms'] ?? null)) {
            return;
        }

        $mode = $this->config->get('telemetry.requests', 'all');
        $slow = $this->config->get('telemetry.slow_request_ms', 500);
        $line = $mode === 'all' || ($mode === 'slow' && $measured['ms'] >= (is_numeric($slow) ? (float) $slow : 500.0));
        $route = RouteName::of($request, $this->operationParameters());

        $this->container->make(Reporter::class)->unit('request', $route, [
            'route' => $route,
            'method' => $request->getMethod(),
            'status' => $response->getStatusCode(),
            'auth' => ($measured['auth'] ?? false) === true,
            'boot_ms' => round($measured['boot_ms'], 1),
        ], $this->container->make(Recorder::class), $measured['ms'], $line);
    }

    private function ignored(Request $request): bool
    {
        $patterns = $this->config->get('telemetry.ignore', []);

        return is_array($patterns) && $patterns !== [] && $request->is(...array_values(array_filter($patterns, is_string(...))));
    }

    private function header(Recorder $recorder, float $total, float $boot, bool $signedIn): ?string
    {
        $mode = $this->config->get('telemetry.server_timing', 'auto');
        if ($mode === true) {
            $mode = 'full';
        }

        return match (true) {
            $mode === 'full', $mode === 'auto' && $signedIn => ServerTiming::full($recorder, $total, $boot),
            $mode === 'total', $mode === 'auto' => ServerTiming::total($total),
            default => null,
        };
    }

    /** Whether the request ended with a person known to the default guard — without loading anyone. */
    private function signedIn(): bool
    {
        try {
            return $this->container->make(Auth::class)->guard()->hasUser();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function operationParameters(): array
    {
        $names = $this->config->get('telemetry.route_parameters', []);

        return is_array($names) ? array_values(array_filter($names, is_string(...))) : [];
    }
}
