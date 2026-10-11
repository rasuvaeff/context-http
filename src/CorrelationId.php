<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http;

/**
 * Immutable request-scoped correlation identifier.
 *
 * The value is bounded (1–128 characters) and limited to a log-safe charset
 * (`A-Z a-z 0-9 . _ : -`), because it typically comes from a client header
 * and ends up in logs and downstream requests.
 *
 * @api
 */
final readonly class CorrelationId
{
    private const string PATTERN = '/^[A-Za-z0-9._:-]{1,128}\z/';

    public function __construct(public string $value)
    {
        if (!self::isValid($value)) {
            throw new \InvalidArgumentException('Correlation ID must be 1-128 characters of A-Z, a-z, 0-9, ".", "_", ":" or "-"');
        }
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }

    /**
     * A fresh random identifier: 32 lowercase hex characters.
     */
    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }
}
