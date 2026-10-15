<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Internal;

use Psr\Http\Message\RequestInterface;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\Http\CorrelationId;

/**
 * Adds the remaining budget and the correlation id to an outgoing request.
 *
 * @internal
 */
final readonly class OutboundHeaders
{
    public function __construct(
        private ?string $deadlineHeader,
        private ?string $correlationHeader,
    ) {}

    /**
     * `$remainingMs` is read by the caller before its `assertActive()`, so
     * the header never carries the `0` of an expired context.
     */
    public function apply(RequestInterface $request, Context $context, ?float $remainingMs): RequestInterface
    {
        if ($this->deadlineHeader !== null && $remainingMs !== null) {
            $request = $request->withHeader($this->deadlineHeader, (string) (int) $remainingMs);
        }

        $correlationId = $context->value(CorrelationId::class);
        if ($this->correlationHeader !== null && $correlationId instanceof CorrelationId && !$request->hasHeader($this->correlationHeader)) {
            return $request->withHeader($this->correlationHeader, $correlationId->value);
        }

        return $request;
    }
}
