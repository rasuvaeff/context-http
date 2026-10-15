<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Tests\Support;

use Rasuvaeff\Context\WireValue;

/** Any string at all: exercises the percent-encoding of the baggage header. */
final readonly class Note implements WireValue
{
    public function __construct(public string $value) {}

    #[\Override]
    public function toWire(): string
    {
        return $this->value;
    }

    #[\Override]
    public static function fromWire(string $wire): static
    {
        return new self($wire);
    }
}
