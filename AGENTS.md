# AGENTS.md — context-http

Guidance for AI agents working on this package. Read before changing code.

## What this is

PSR-15 and PSR-18 adapters for `rasuvaeff/context` (namespace
`Rasuvaeff\Context\Http`): `ContextMiddleware` owns a request-scoped context,
`PreflightClient` checks it around a PSR-18 call, `ContextClient` maps the
remaining budget to Guzzle options, `CorrelationId` is the validated request
id. Outbound budget/correlation headers go through `@internal
Internal\OutboundHeaders`.

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Never trust a header.** A correlation id is validated
   (`/^[A-Za-z0-9._:-]{1,128}\z/`, anchored with `\z`) and replaced when
   invalid; an incoming budget is whole milliseconds 1–999999999 and may only
   shorten the configured timeout.
4. **Preserve the public contract.** Update README + README.ru + llms.txt + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- Keep the core (`rasuvaeff/context`) dependency-free; PSR and Guzzle code
  belongs here. Guzzle stays optional: `ContextClient` takes `object` and
  checks for `request()`; never type against `GuzzleHttp\ClientInterface`.
- PSR-18 cannot configure a transport timeout. `PreflightClient` documents
  that limitation instead of pretending otherwise.
- `ContextClient` sends `http_errors => false`, the body as its stream and
  the protocol version. A transport failure after expiry becomes
  `ContextExpiredException` with the failure as `previous`.
- `ContextMiddleware` cancels its controller in `finally` — also when the
  handler throws or a context exception is mapped to a response.
  Exception mapping is opt-in (`responseFactory`); without it the exceptions
  propagate unchanged.
- An explicit correlation header on an outgoing request wins over the
  context's value.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.
- **CI workflows are SHA-pinned.** Every `uses:` in `.github/workflows/*.yml`
  references a 40-char commit SHA with a `# vN` trailing comment. Never revert
  to floating `@vN` tags. Workflows carry `permissions: { contents: read }`
  and `persist-credentials: false` on every checkout. Verify with
  `zizmor --persona=auditor .github/`.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit;
  and `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build`; if the change affects public API or release safety,
  also run `make release-check`. Paste the output.
