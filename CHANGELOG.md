# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-11-19

### Added

- `Server-Timing` on every answer: the application's total, boot, database
  (with the number of queries), Redis, outgoing HTTP and the application's own
  spans; `auto` gives a guest the total alone.
- One flat JSON event per request, queued job and console command — route,
  status, milliseconds, queries, Redis, outgoing HTTP, memory — written after
  the answer has gone; `stderr`, a log channel, or a sink of one's own.
- A line per slow statement and per statement repeated in one unit (N+1), the
  statement's shape without its values; a line per slow outgoing call, its path
  without identifiers.
- `Telemetry::measure()` — spans of the application's own.
- `MemorySink` for tests.
