<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Internal;

use Psr\Http\Message\RequestInterface;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\Http\CorrelationId;
use Rasuvaeff\Context\WireCodec;

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
        private ?WireCodec $wireCodec = null,
        private string $baggageHeader = 'baggage',
    ) {}

    /**
     * `$budgetMs` comes from the caller's `Context::budgetMs()`, so the
     * header never carries the `0` of an expired context.
     *
     * @param positive-int|null $budgetMs
     */
    public function apply(RequestInterface $request, Context $context, ?int $budgetMs): RequestInterface
    {
        if ($this->deadlineHeader !== null && $budgetMs !== null) {
            $request = $request->withHeader($this->deadlineHeader, (string) $budgetMs);
        }

        $correlationId = $context->value(CorrelationId::class);
        if ($this->correlationHeader !== null && $correlationId instanceof CorrelationId && !$request->hasHeader($this->correlationHeader)) {
            $request = $request->withHeader($this->correlationHeader, $correlationId->value);
        }

        if (!$this->wireCodec instanceof WireCodec) {
            return $request;
        }

        $baggage = Baggage::merge(
            $request->getHeaderLine($this->baggageHeader),
            $this->wireCodec->encodeValues($context),
        );

        return $baggage === '' ? $request : $request->withHeader($this->baggageHeader, $baggage);
    }
}
