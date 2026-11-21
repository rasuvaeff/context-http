<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\Http\Internal\OutboundHeaders;
use Rasuvaeff\Context\Http\Internal\TransportFailure;
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
 * With `expireTransportFailures` a client exception thrown after the
 * deadline passed surfaces as `ContextExpiredException` (the original as
 * `previous`), as in {@see ContextClient}; it is off by default because it
 * changes the exception type. {@see withContext()} reuses the configuration
 * for another request.
 *
 * @api
 */
final readonly class PreflightClient implements ClientInterface
{
    private OutboundHeaders $outbound;

    public function __construct(
        private ClientInterface $client,
        private Context $context,
        private ?string $deadlineHeader = null,
        private ?string $correlationHeader = null,
        private ?WireCodec $wireCodec = null,
        private string $baggageHeader = 'baggage',
        private bool $expireTransportFailures = false,
    ) {
        $this->outbound = new OutboundHeaders($deadlineHeader, $correlationHeader, $wireCodec, $baggageHeader);
    }

    /**
     * The same client and configuration, checking `$context` instead.
     */
    public function withContext(Context $context): self
    {
        return new self(
            client: $this->client,
            context: $context,
            deadlineHeader: $this->deadlineHeader,
            correlationHeader: $this->correlationHeader,
            wireCodec: $this->wireCodec,
            baggageHeader: $this->baggageHeader,
            expireTransportFailures: $this->expireTransportFailures,
        );
    }

    /**
     * @throws ClientExceptionInterface
     */
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $request = $this->outbound->apply($request, $this->context, $this->context->budgetMs());

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $failure) {
            if ($this->expireTransportFailures) {
                TransportFailure::rethrow($failure, $this->context);
            }

            throw $failure;
        }

        $this->context->assertActive();

        return $response;
    }
}
