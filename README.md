# rasuvaeff/context-http

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/context-http/v)](https://packagist.org/packages/rasuvaeff/context-http)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/context-http/downloads)](https://packagist.org/packages/rasuvaeff/context-http)
[![Build](https://github.com/rasuvaeff/context-http/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/context-http/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/context-http/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/context-http/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/context-http/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/context-http/php)](https://packagist.org/packages/rasuvaeff/context-http)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

PSR-15 and PSR-18 adapters for [`rasuvaeff/context`](https://github.com/rasuvaeff/context):
a request-scoped deadline, cancellation and correlation id, propagated across service calls.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference you can share with the model.

## Requirements

- PHP 8.3+
- `rasuvaeff/context` ^0.2
- `psr/http-message` ^2.0, `psr/http-factory` ^1.0, `psr/http-client` ^1.0,
  `psr/http-server-handler` ^1.0, `psr/http-server-middleware` ^1.0
- `guzzlehttp/guzzle` ^7 only for `ContextClient` (optional)

## Installation

```bash
composer require rasuvaeff/context-http
```

## Limitations

These adapters bound how long a request **waits**; they do not abort work
already running. See the transport matrix in
[`rasuvaeff/context`](https://github.com/rasuvaeff/context#limitations).

- **`ContextMiddleware` does not stop the handler.** The deadline is observed
  only where the handler calls `assertActive()` (directly or through an
  adapter); the 504/499 mapping applies to the exception that call throws.
  It does not detect a client disconnect either — `499` is produced only if
  your code cancels the controller. Keep the budget below the server's own
  limit (PHP-FPM `request_terminate_timeout`, PHP `max_execution_time`), or
  the worker is killed before the context can answer.
- **`PreflightClient` cannot time out a request.** PSR-18 has no per-request
  options: it checks before sending and after receiving, and the client's
  own timeout decides how long the call blocks.
- **`ContextClient` maps only the remaining budget.** The synchronous Guzzle
  call returns at that timeout; a `cancel()` issued meanwhile is seen only
  after it returns. `remainingMs()` is at least 1 ms while the context is
  active, because curl treats a zero timeout as no timeout at all.

## Usage

### `ContextMiddleware` (PSR-15)

Creates one context per request, publishes it as request attributes and
cancels it in `finally`:

```php
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextController;
use Rasuvaeff\Context\Http\ContextMiddleware;
use Rasuvaeff\Context\Http\CorrelationId;

$middleware = new ContextMiddleware(
    timeout: 5.0,                              // seconds, or fn (ServerRequestInterface): float
    deadlineHeader: 'X-Request-Timeout-Ms',    // optional: accept an upstream budget
    responseFactory: $psr17Factory,            // optional: map context exceptions to 504/499
);

// in the handler
$context = $request->getAttribute(Context::class);
$controller = $request->getAttribute(ContextController::class);
$correlationId = $request->getAttribute(CorrelationId::class);   // always set
```

| Argument | Default | Effect |
|---|---|---|
| `timeout` | `30.0` | Request budget in seconds, or a callable deriving it from the request |
| `correlationHeader` | `X-Correlation-ID` | Incoming correlation header |
| `contextAttribute` / `controllerAttribute` / `correlationAttribute` | class names | Request attribute names |
| `timeSource` | `HrTimeSource` | Monotonic clock (tests) |
| `deadlineHeader` | `null` | Incoming budget in whole milliseconds (1–999999999); it only **shortens** the timeout — a longer or malformed value is ignored |
| `correlationIdGenerator` | `CorrelationId::generate(...)` | Used when the header is missing or invalid |
| `responseFactory` | `null` | When set, `ContextExpiredException` → `expiredStatus`, `ContextCancelledException` → `cancelledStatus`; otherwise they propagate |
| `expiredStatus` / `cancelledStatus` | `504` / `499` | Mapped status codes |
| `wireCodec` | `null` | A `rasuvaeff/context` `WireCodec`: allow-listed request values are read from the `baggage` header |
| `baggageHeader` | `baggage` | Header carrying the values (W3C Baggage) |

Every request gets a `CorrelationId`: the incoming header when valid,
otherwise a freshly generated one. The value is also stored in the context
(`$context->value(CorrelationId::class)`), so outbound clients can forward it.

### `PreflightClient` (PSR-18)

```php
use Rasuvaeff\Context\Http\PreflightClient;

$client = new PreflightClient(
    $psr18Client,
    $context,
    deadlineHeader: 'X-Request-Timeout-Ms',   // optional: send the remaining budget
    correlationHeader: 'X-Correlation-ID',    // optional: forward the correlation id
);
$response = $client->sendRequest($request);
```

Checks `assertActive()` before starting a request and after receiving the
response. PSR-18 has no transport options, so it **cannot impose a timeout on
a blocking client call** — configure the client's own timeout, or use
`ContextClient` with Guzzle. An explicit correlation header on the request
wins over the context's.

### `ContextClient` (Guzzle)

```php
use Rasuvaeff\Context\Http\ContextClient;

[$context, $controller] = Context::background()->withTimeout(2.0);
$response = (new ContextClient($guzzle, deadlineHeader: 'X-Request-Timeout-Ms'))->send($request, $context);
$controller->cancel();
```

- The remaining budget becomes Guzzle's `timeout` and `connect_timeout`.
- The request body is sent as its stream; the protocol version is kept.
- `http_errors` is off: a 4xx/5xx response is **returned**, as with PSR-18.
- A transport failure after the budget ran out is a `ContextExpiredException`
  whose `previous` is the transport exception; after a cancellation it is a
  `ContextCancelledException`; otherwise the transport exception propagates.
- The constructor rejects an object without a Guzzle-compatible `request()`.

### Propagating one deadline across services

```
caller ──X-Request-Timeout-Ms: 800──▶ ContextMiddleware(deadlineHeader)
                                       └─ PreflightClient(deadlineHeader) ──X-Request-Timeout-Ms: 790──▶ next service
```

Use the same header name on both sides. The receiving middleware never
trusts a budget longer than its own `timeout`.

### Request values across services (`baggage`)

The budget crosses services on its own header; request-scoped values (tenant,
locale, experiment flags) travel in the standard W3C `baggage` header through a
`rasuvaeff/context` `WireCodec`: only classes implementing `WireValue`, under
aliases you allow.

```php
use Rasuvaeff\Context\WireCodec;

$codec = new WireCodec(['tenant' => TenantId::class, 'locale' => Locale::class]);

// incoming: baggage: tenant=acme,locale=de → $context->value(TenantId::class)
$middleware = new ContextMiddleware(timeout: 5.0, deadlineHeader: 'X-Request-Timeout-Ms', wireCodec: $codec);

// outgoing to YOUR services only — see Security
$internal = new ContextClient($guzzle, deadlineHeader: 'X-Request-Timeout-Ms', wireCodec: $codec);
```

| Side | Behaviour |
|---|---|
| Incoming | Members with an allow-listed alias are decoded through `WireValue::fromWire()`; an invalid value, an unknown key or a malformed member is dropped, never thrown; a header over 8192 bytes is ignored. The values never touch the budget |
| Outgoing | The context's values are appended to `baggage`; members already present (e.g. from the OpenTelemetry SDK) are kept verbatim and a key already present is never overridden. Values are percent-encoded; an entry that would exceed 8192 bytes or 180 members is dropped |

Trace ids are not carried here: that is `traceparent`, owned by OpenTelemetry.

### Queue consumers

Keep the controller with the message owner and derive a fresh context from
the message TTR; call `assertActive()` before each step and NACK or
dead-letter on `ContextExpiredException`. The package does not depend on a
queue implementation.

### Public API

| Class | Description |
|---|---|
| `ContextMiddleware` | PSR-15: request context, controller and correlation id attributes; budget header; exception mapping |
| `PreflightClient` | PSR-18 decorator: pre/post context checks, outbound budget and correlation headers |
| `ContextClient` | Guzzle-compatible adapter: budget → timeouts, PSR-18 error semantics, expiry mapping |
| `CorrelationId` | Validated correlation id (`isValid()`, `generate()`) |

## Security

- **Outgoing `baggage` is sent with every request of that client.** Pass a
  `wireCodec` only to clients that call your own services: a client for
  Stripe or any third-party API configured with a codec would hand them your
  tenant ids and flags. Use separate client instances for internal and
  external calls.
- **Incoming `baggage` is untrusted.** Only allow-listed aliases are read, and
  each value goes through your `WireValue::fromWire()` — validate there and
  throw on anything unexpected; the middleware drops such values.
- **Correlation ids are validated**: 1–128 characters of `A-Z a-z 0-9 . _ : -`.
  An invalid incoming value is replaced, never logged or forwarded — no log
  injection, no unbounded header values.
- **Incoming budgets are untrusted**: only whole milliseconds in 1–999999999
  are accepted, and they can only shorten the configured timeout.
- Context exceptions mapped to responses carry no details; everything else
  propagates to your error handler.
- All adapters are cooperative: they prevent work from starting or a response
  from being consumed after termination, but cannot interrupt a generic
  blocking PSR-18 call.

## Examples

See [examples/](examples/) for runnable scripts.

| Script | Shows | Needs server? |
|---|---|---|
| `basic.php` | Middleware reading an upstream budget and correlation id, `PreflightClient` forwarding both downstream, mapped responses | no |

## Development

No PHP/Composer on the host — run in Docker via the `composer:2` image:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make: `make build`, `make cs-fix`, `make test`, `make test-coverage`,
`make mutation`, `make release-check`.

## License

[BSD-3-Clause](LICENSE.md)
