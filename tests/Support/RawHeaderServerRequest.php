<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Tests\Support;

use Nyholm\Psr7\ServerRequest;

/**
 * A server request whose raw headers bypass PSR-7 validation, so a test can
 * hand the middleware bytes a real server may still deliver (a trailing
 * newline) without the PSR-7 implementation rejecting them first.
 */
final class RawHeaderServerRequest extends ServerRequest
{
    /** @var array<lowercase-string, string> */
    private array $raw = [];

    /**
     * @param array<string, string> $rawHeaders
     */
    public function __construct(string $method, string $uri, array $rawHeaders)
    {
        parent::__construct($method, $uri);

        foreach ($rawHeaders as $name => $value) {
            $this->raw[strtolower($name)] = $value;
        }
    }

    #[\Override]
    public function hasHeader($header): bool
    {
        return \is_string($header) && \array_key_exists(strtolower($header), $this->raw) || parent::hasHeader($header);
    }

    #[\Override]
    public function getHeader($header): array
    {
        return \is_string($header) && \array_key_exists(strtolower($header), $this->raw)
            ? [$this->raw[strtolower($header)]]
            : parent::getHeader($header);
    }

    #[\Override]
    public function getHeaderLine($header): string
    {
        return implode(', ', $this->getHeader($header));
    }
}
