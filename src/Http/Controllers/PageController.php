<?php

declare(strict_types=1);

namespace Dskripchenko\Telemetry\Http\Controllers;

use Dskripchenko\Telemetry\Sinks\Sink;
use Dskripchenko\Telemetry\Support\PageEvent;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The page as the browser saw it: the collector script, and the beacon it
 * sends once per page load.
 */
final class PageController
{
    /** A beacon is a few hundred bytes; more is not one. */
    private const int MAX_BYTES = 4096;

    public function __construct(private readonly Repository $config, private readonly Sink $sink) {}

    /** The collector: one small script, the same for every page. */
    public function script(Request $request): Response
    {
        $source = (string) file_get_contents(__DIR__.'/../../../resources/js/page.js');
        $script = str_replace('__ENDPOINT__', '/'.trim($this->path(), '/').'/page', $source);
        $etag = '"'.substr(hash('sha256', $script), 0, 16).'"';

        $response = new Response($script, 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->isNotModified($request);

        return $response;
    }

    /** The beacon: taken when it is a page load of this very site, dropped silently otherwise. */
    public function store(Request $request): Response
    {
        $accepted = new Response('', 204);
        // Another site's page has nothing to say about this one's loads.
        $site = $request->headers->get('Sec-Fetch-Site');
        if ($site !== null && $site !== 'same-origin') {
            return $accepted;
        }

        $body = $request->getContent();
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return $accepted;
        }
        try {
            $sent = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $accepted;
        }
        $event = is_array($sent) ? PageEvent::from($sent) : null;
        if ($event !== null) {
            $app = $this->config->get('telemetry.app', 'app');
            $this->sink->write(['event' => 'page', 'app' => is_string($app) ? $app : 'app', ...$event]);
        }

        return $accepted;
    }

    private function path(): string
    {
        $path = $this->config->get('telemetry.page.path', 'telemetry');

        return is_string($path) && $path !== '' ? $path : 'telemetry';
    }
}
