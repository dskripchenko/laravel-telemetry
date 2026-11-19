# dskripchenko/laravel-telemetry

Performance telemetry for Laravel without an agent and without a database.

- **A `Server-Timing` header on every answer** — the application's total, its
  boot, the database (with the number of queries), Redis, outgoing HTTP and the
  spans the application marks itself. The browser's network panel shows it under
  a request's timing: where the server spent its time is one click away.
- **One structured line per request, queued job and console command** — route,
  status, milliseconds, query count and time, Redis, outgoing HTTP, memory. JSON
  on stderr by default: a container's log, taken by whatever collects it
  (Vector, Fluent Bit, Promtail) into whatever stores it (VictoriaLogs, Loki,
  Elasticsearch). Percentiles per route are a query away, with no tables to
  rotate.
- **The queries behind a slow unit** — a statement that took too long, and a
  statement run many times in one request (the usual face of an N+1), each as a
  line of its own with the statement's shape and none of its values.
- **Slow outgoing calls** — host, path without identifiers, status, time.

Nothing is stored in the application, nothing is sent anywhere: the header goes
to the browser that asked, the lines go to the process's own log. Statements
are logged without their values and paths without their identifiers, so the
events carry no data of the people the application serves.

Laravel 11, 12 and 13; PHP 8.3+.

## Install

```bash
composer require dskripchenko/laravel-telemetry
```

That is all: the service provider is discovered and the middleware goes in
front of the application's own. In production the events go to stderr; anywhere
else there is the header alone, so a developer's terminal stays quiet
(`TELEMETRY_SINK=stderr` turns the lines on). To change the defaults:

```bash
php artisan vendor:publish --tag=telemetry-config
```

## The header

```
Server-Timing: app;dur=41.3, boot;dur=8.0, db;desc="db x5";dur=7.3, cache;desc="redis x6";dur=1.2, http;desc="http x1";dur=18.9
```

| Metric | What it is |
|---|---|
| `app` | From the request's first byte to the answer leaving PHP |
| `boot` | The framework's start, before the first middleware |
| `db` | Time in queries; their number in the description |
| `cache` | Time in Redis commands — cache, sessions, queues (needs `illuminate/redis`) |
| `http` | Time in outgoing calls of Laravel's HTTP client |
| anything else | A span the application marked with `Telemetry::measure()` |

`TELEMETRY_SERVER_TIMING` chooses who sees what: `auto` (the default) gives
everything to a signed-in person and the total alone to a guest — how many
queries a failed sign-in ran is nobody's business; `full`, `total` and `off` do
what they say.

## The lines

```json
{"event":"request","app":"shop","request_id":"…","route":"api/v1/orders/list","method":"GET","status":200,"auth":true,"boot_ms":8.0,"ms":41.3,"db_n":5,"db_ms":7.3,"cache_n":6,"cache_ms":1.2,"http_n":1,"http_ms":18.9,"mem_mb":14.0}
{"event":"query","app":"shop","in":"request","unit":"api/v1/orders/list","kind":"repeated","sql":"select * from \"users\" where \"id\" = ? limit ?","n":23,"ms":41.0,"max_ms":3.1}
{"event":"query","app":"shop","in":"job","unit":"App\\Jobs\\Recount","kind":"slow","sql":"select count(*) from \"orders\" where \"state\" = ?","n":1,"ms":153.2,"max_ms":153.2}
{"event":"call","app":"shop","in":"request","unit":"api/v1/orders/show","method":"GET","host":"billing.internal","path":"/api/v1/invoices/{id}","status":200,"ms":412.6}
{"event":"job","app":"shop","job":"App\\Jobs\\SendDigest","queue":"mail","status":"ok","attempt":1,"ms":88.4,"db_n":3,"db_ms":4.0,"cache_n":2,"cache_ms":0.3,"http_n":1,"http_ms":70.2,"mem_mb":22.0}
{"event":"command","app":"shop","command":"reports:build","status":"ok","ms":5210.7,"db_n":140,"db_ms":3900.1,"cache_n":0,"cache_ms":0.0,"http_n":0,"http_ms":0.0,"mem_mb":64.0}
```

- A request is named by its **route**, not its address: `orders/{order}` stays a
  pattern, so the list of routes is short and identifiers stay out of the log.
  Parameters that name an operation rather than an object are filled in
  (`route_parameters`; by default `version`, `controller`, `action` —
  `api/{version}/{controller}/{action}` becomes `api/v1/orders/list`).
- `request_id` is taken from Laravel's `Context` when the application puts one
  there, so a line is found by the ID a person was shown.
- A job run by the `sync` driver inside a request is that request's work, not a
  line of its own. A console command inside another is part of the outer one.
- Probes are not measured (`ignore`: `up`, `health`), nor are the commands that
  only start others or never end (`ignore_commands`).

### What gets a line of its own

| Setting | Default | |
|---|---|---|
| `TELEMETRY_REQUESTS` | `all` | `slow` — only requests at or over `TELEMETRY_SLOW_REQUEST_MS` (500); `off` — none |
| `TELEMETRY_SLOW_QUERY_MS` | `100` | A statement this slow; `0` — off |
| `TELEMETRY_REPEATED_QUERIES` | `8` | A statement run this many times in one unit; `0` — off |
| `TELEMETRY_QUERY_EVENTS` | `5` | At most this many query lines per unit, the heaviest first |
| `TELEMETRY_SLOW_CALL_MS` | `300` | An outgoing call this slow; `0` — off |

### Where the lines go

`TELEMETRY_SINK`: `stderr` (the default in production), `log` (a channel of
`config/logging.php`, named by `TELEMETRY_LOG_CHANNEL`; the event is the
context), `null`, or a class of your own implementing
`Dskripchenko\Telemetry\Sinks\Sink`. Outside production the default is `null`.

## Marks of your own

```php
use Dskripchenko\Telemetry\Telemetry;

$pdf = Telemetry::measure('render', fn () => $renderer->render($document));
```

The span is in the header (`render;dur=120.4`) and in the unit's line
(`x_render_ms`).

## Reading it

With the lines in VictoriaLogs (LogsQL):

```
# The slowest routes of the last hour: hits, median, 95th percentile
_time:1h event:request | stats by (app, route) count() hits, quantile(0.5, ms) p50, quantile(0.95, ms) p95 | sort by (p95) desc

# Where the time goes: share of the database and of outgoing calls
_time:1h event:request | stats by (app, route) sum(ms) total, sum(db_ms) db, sum(http_ms) http | sort by (total) desc

# N+1 candidates and slow statements
_time:24h event:query | stats by (app, unit, kind, sql) count() seen, max(n) times, max(max_ms) slowest | sort by (seen) desc
```

## In tests

Telemetry is off under `APP_ENV=testing` unless `TELEMETRY_ENABLED=true`. To
assert on events:

```php
use Dskripchenko\Telemetry\Testing\MemorySink;

$sink = MemorySink::install($this->app);
$this->get('/orders')->assertOk();

expect($sink->of('query'))->toBe([]);                    // no N+1, nothing slow
expect($sink->of('request')[0]['db_n'])->toBeLessThan(10);
```

## Cost

A listener call per query, per Redis command and per outgoing call; one
`json_encode` and one write per unit, after the answer has gone
(`terminate()`). No queries, no network, no state between requests.

## License

MIT.
