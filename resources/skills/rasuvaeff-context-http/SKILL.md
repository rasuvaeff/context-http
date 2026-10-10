---
name: rasuvaeff-context-http
description: >-
  PSR HTTP adapters for rasuvaeff/context — ContextMiddleware,
  PreflightClient, ContextClient and CorrelationId. Use when connecting
  request deadlines or cancellation to PSR-15, PSR-18 or Guzzle-compatible
  HTTP code.
---

# rasuvaeff/context-http

HTTP adapters for `Rasuvaeff\Context\Context`.

## Rules

1. `ContextMiddleware` owns one context per PSR-15 request and publishes the
   context/controller as request attributes. It cancels the controller in
   `finally`.
2. `PreflightClient` only checks before and after generic PSR-18
   `sendRequest()`. PSR-18 has no transport-options argument, so it cannot
   enforce an in-flight timeout.
3. `ContextClient::send(RequestInterface, Context)` is the Guzzle-compatible
   explicit API. It maps remaining budget to both `timeout` and
   `connect_timeout`.
4. Context cancellation is cooperative. Do not promise that a synchronous
   PSR-18 or curl call is interrupted.
5. Correlation IDs are typed request-scoped values; do not serialize Context
   itself into a header.

```php
[$context, $controller] = Context::background()->withTimeout(2.0);
$response = (new ContextClient($guzzle))->send($request, $context);
$controller->cancel();
```

Read `vendor/rasuvaeff/context-http/llms.txt` before guessing constructor
attributes or adapter behavior.
