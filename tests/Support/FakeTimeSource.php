<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Tests\Support;

use Rasuvaeff\Context\TimeSource;

final class FakeTimeSource implements TimeSource
{
    public function __construct(private int $nowNs = 1_000_000_000) {}

    #[\Override]
    public function nowNs(): int
    {
        return $this->nowNs;
    }

    public function advanceMs(int $milliseconds): void
    {
        $this->nowNs += $milliseconds * 1_000_000;
    }
}
