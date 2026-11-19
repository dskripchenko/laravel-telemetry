<?php

declare(strict_types=1);

return [

    /*
     * Off in tests unless asked for: the header and the log lines would only
     * be noise there.
     */
    'enabled' => env('TELEMETRY_ENABLED', env('APP_ENV') !== 'testing'),

    /*
     * The application's name in every event: several services write to one
     * log, and this tells them apart.
     */
    'app' => env('TELEMETRY_APP', env('APP_NAME', 'app')),

    /*
     * The Server-Timing header, read in the browser's network panel:
     *   auto  — everything for a signed-in person, the total alone for a guest
     *           (how many queries a failed sign-in ran is nobody's business);
     *   full  — everything for everyone;
     *   total — the total alone;
     *   off   — no header.
     */
    'server_timing' => env('TELEMETRY_SERVER_TIMING', 'auto'),

    /*
     * Where the events go:
     *   stderr — one JSON object per line on the process's stderr (a container's log);
     *   log    — a channel of config/logging.php (log_channel below), the event as context;
     *   null   — nowhere (the header stays);
     *   or a class implementing Dskripchenko\Telemetry\Sinks\Sink.
     * By default stderr in production and nowhere elsewhere: a developer keeps
     * the header in the browser and gets no lines in the terminal.
     */
    'sink' => env('TELEMETRY_SINK', env('APP_ENV') === 'production' ? 'stderr' : 'null'),

    'log_channel' => env('TELEMETRY_LOG_CHANNEL'),

    /*
     * A line per request: all — every one (percentiles need them all);
     * slow — only those at or over slow_request_ms; off — none.
     */
    'requests' => env('TELEMETRY_REQUESTS', 'all'),

    'slow_request_ms' => env('TELEMETRY_SLOW_REQUEST_MS', 500),

    /*
     * Queries worth a line of their own: one that took slow_query_ms or more,
     * and one statement run repeated_queries times or more in a request, a job
     * or a command — the usual face of an N+1. At most query_events of them per
     * unit of work, the heaviest first. The statement goes without its values.
     */
    'slow_query_ms' => env('TELEMETRY_SLOW_QUERY_MS', 100),

    'repeated_queries' => env('TELEMETRY_REPEATED_QUERIES', 8),

    'query_events' => env('TELEMETRY_QUERY_EVENTS', 5),

    /*
     * An outgoing HTTP call that took this long gets a line: its host, its
     * path without identifiers, its status.
     */
    'slow_call_ms' => env('TELEMETRY_SLOW_CALL_MS', 300),

    /*
     * Requests that are not measured: probes that come every few seconds.
     * Patterns as in Request::is().
     */
    'ignore' => ['up', 'health', 'telemetry/*'],

    /*
     * The page as the person's browser saw it. The package serves a small
     * collector script and takes its beacon — one per page load:
     *
     *     <script defer src="/telemetry/page.js"></script>
     *
     * and, when the application is usable,
     *
     *     performance.mark('app:ready', { detail: { page: 'orders.list' } })
     *
     * path — the prefix of both addresses; throttle — beacons a minute from
     * one address.
     */
    'page' => [
        'enabled' => env('TELEMETRY_PAGE', true),
        'path' => env('TELEMETRY_PAGE_PATH', 'telemetry'),
        'throttle' => env('TELEMETRY_PAGE_THROTTLE', 60),
    ],

    /*
     * Route parameters that name an operation, not an object: they stay in the
     * route's name (api/{version}/{controller}/{action} → api/v1/tasks/list).
     * Every other parameter stays a placeholder, so identifiers and tokens of a
     * path reach neither the log nor the list of routes.
     */
    'route_parameters' => ['version', 'controller', 'action'],

    /*
     * Console commands that only start others or never end: no line for them.
     */
    'ignore_commands' => [
        'schedule:run', 'schedule:work', 'schedule:finish',
        'queue:work', 'queue:listen', 'queue:restart',
        'package:discover', 'list', 'help', 'tinker',
    ],

];
