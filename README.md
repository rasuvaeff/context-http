# rasuvaeff/context-http

PSR HTTP adapters for [`rasuvaeff/context`](../context/README.md).

## Installation

```bash
composer require rasuvaeff/context-http
```

PSR-15 middleware creates one request-scoped context and publishes it as a
request attribute. The request's `X-Correlation-ID` header, when present, is
available as a typed `CorrelationId` value.

```php
$middleware = new ContextMiddleware(timeout: 3.0);
// The handler receives Context::class and ContextController::class attributes.
```

`PreflightClient` is a PSR-18 decorator. It checks `assertActive()` before
starting a request and after receiving its response. PSR-18 has no transport
options, so it cannot impose a timeout on a blocking client call.

For Guzzle, use the explicit adapter. It maps the remaining context budget to
Guzzle's `timeout` and `connect_timeout` options:

```php
[$context, $controller] = Context::background()->withTimeout(2.0);
$response = (new ContextClient($guzzle))->send($request, $context);
$controller->cancel();
```

The core context is cooperative: it does not interrupt an arbitrary blocking
syscall. Check the context between retries, batches and queue steps.

For queue consumers, keep the controller with the message owner and derive a
fresh context from the message TTR. Call `assertActive()` before each step;
on `ContextExpiredException`, NACK or dead-letter according to the queue's
policy. The package intentionally does not depend on a queue implementation.

## Public API

| Class | Description |
|---|---|
| `ContextMiddleware` | PSR-15 request context and controller attributes |
| `PreflightClient` | PSR-18 pre/post context checks |
| `ContextClient` | Guzzle-compatible explicit context adapter |
| `CorrelationId` | Typed request-scoped correlation identifier |
