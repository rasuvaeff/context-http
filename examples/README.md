# Examples

Run from the package root after `composer install`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic.php
```

| Script | Shows | Needs server? |
|---|---|---|
| `basic.php` | Middleware reading an upstream budget and correlation id, `PreflightClient` forwarding both downstream, mapped responses | no |
