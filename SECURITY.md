# Security Policy

## Supported versions

| Version | Supported |
|---------|-----------|
| 0.2.x   | yes       |
| 0.1.x   | no        |

## Reporting a vulnerability

Please report vulnerabilities privately through GitHub's private vulnerability
reporting: **Security → Report a vulnerability** on
[github.com/dskripchenko/laravel-telemetry](https://github.com/dskripchenko/laravel-telemetry/security/advisories/new).
Do not open a public issue for a vulnerability. Include affected versions and, if
possible, a proof of concept.

You will get an answer within five working days. A confirmed issue is fixed in a
patch release of every supported line, published as a GitHub security advisory once
the release is out, and credited in the changelog unless you ask otherwise.

## Security model in short

- Nothing leaves the application: the header goes to the browser that asked, the
  events go to the process's own log (or the sink the application names).
- Events carry no values of the data: a statement is logged as its shape (strings,
  numbers and lists replaced), an outgoing call as its host and a path with
  identifiers and tokens masked and the query dropped, a request as its route's
  pattern.
- A guest gets the total alone in `Server-Timing` by default: the number of queries
  and the time in them would tell a stranger what a failed sign-in did.
- The page beacon is an endpoint anyone who can open the site can call: it keeps
  only the collector's fields, each of its type and within its bounds, names a page
  by a short pattern or a masked path, takes same-origin requests only, limits them
  per address and answers 204 whatever it did — it cannot be used to write text
  into the log or to learn anything.
- Telemetry never fails the work it measures: a sink that cannot write is silent.
