<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\Http\Internal\OutboundHeaders;
use Rasuvaeff\Context\WireCodec;

/**
 * Checks a context around a generic PSR-18 request.
 *
 * PSR-18 has no transport-options argument, so this decorator cannot impose
 * a timeout on an in-flight synchronous client. It prevents work from
 * starting after termination and prevents a terminated response from being
 * consumed by the caller. With `deadlineHeader` it tells the downstream
 * service how much time is left; with `correlationHeader` it forwards the
 * context's `CorrelationId` (an explicit header on the request wins).
 *
 * @api
 */
final readonly class PreflightClient implements ClientInterface
{
    private OutboundHeaders $outbound;

    public function __construct(
        private ClientInterface $client,
        private Context $context,
        ?string $deadlineHeader = null,
        ?string $correlationHeader = null,
        ?WireCodec $wireCodec = null,
        string $baggageHeader = 'baggage',
    ) {
        $this->outbound = new OutboundHeaders($deadlineHeader, $correlationHeader, $wireCodec, $baggageHeader);
    }

    /**
     * @throws ClientExceptionInterface
     */
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $remainingMs = $this->context->remainingMs();
        $this->context->assertActive();
        $response = $this->client->sendRequest($this->outbound->apply($request, $this->context, $remainingMs));
        $this->context->assertActive();

        return $response;
    }
}
