<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Context\Context;

/**
 * Checks a context around a generic PSR-18 request.
 *
 * PSR-18 has no transport-options argument, so this decorator cannot impose
 * a timeout on an in-flight synchronous client. It prevents work from
 * starting after termination and prevents a terminated response from being
 * consumed by the caller.
 */
final readonly class PreflightClient implements ClientInterface
{
    public function __construct(
        private ClientInterface $client,
        private Context $context,
    ) {}

    /**
     * @throws ClientExceptionInterface
     */
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->context->assertActive();
        $response = $this->client->sendRequest($request);
        $this->context->assertActive();

        return $response;
    }
}
