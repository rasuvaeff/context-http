# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.2.1 — 2026-10-15

- Fixed: a deadline passing inside `ContextClient::send()` could send the
  request with `timeout => 0`, which curl treats as no timeout;
  `PreflightClient` could send a budget header of `0`. Both now read the
  budget before the final check and throw `ContextExpiredException` instead
  (#6).
- Docs: `Limitations` section in both READMEs.

## 0.2.0 — 2026-10-11

- **Deadline propagation** (#1): `ContextMiddleware` accepts an optional
  `deadlineHeader`; an incoming budget in whole milliseconds (1–999999999)
  shortens the configured timeout, a longer or malformed value is ignored.
  `PreflightClient` and `ContextClient` take `deadlineHeader` and send the
  remaining budget downstream.
- **BC — correlation ids** (#2): `CorrelationId` now validates its value
  (1–128 characters of `A-Z a-z 0-9 . _ : -`), adds `isValid()` and
  `generate()`. `ContextMiddleware` generates an id when the header is
  missing or invalid (injectable `correlationIdGenerator`), so every request
  has one, and stores it in the context. Both clients take
  `correlationHeader` to forward it; an explicit header on the request wins.
- **Exception mapping** (#3): with an optional `responseFactory`,
  `ContextMiddleware` turns `ContextExpiredException` into `expiredStatus`
  (504) and `ContextCancelledException` into `cancelledStatus` (499); off by
  default. Adds `psr/http-factory` to the requirements.
- **BC — `ContextClient`** (#4): rejects an object without a Guzzle-compatible
  `request()` method at construction; sends the body as its stream and keeps
  the protocol version; sets `http_errors => false`, so 4xx/5xx responses are
  returned like PSR-18; a transport failure after the budget ran out becomes
  `ContextExpiredException` with the transport exception as `previous`.
- Requires `rasuvaeff/context` ^0.2. Package brought up to the monorepo
  template (`@api`, `#[Covers]`, missing repository files, `README.ru.md`,
  release workflow, runnable example).

## 0.1.0 — 2026-10-10

- Added PSR-15 request context middleware, PSR-18 preflight checks, and the
  explicit Guzzle-compatible `ContextClient` adapter.
