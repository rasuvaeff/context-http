<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http;

/** Immutable request-scoped correlation identifier. */
final readonly class CorrelationId
{
    public function __construct(public string $value)
    {
        if ($value === '') {
            throw new \InvalidArgumentException('Correlation ID must not be empty');
        }
    }
}
