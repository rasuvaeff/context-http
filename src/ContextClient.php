<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Http\Internal\OutboundHeaders;

/**
 * Guzzle adapter with an explicit context argument.
 *
 * Guzzle stays an optional dependency, so the client is typed as object;
 * the constructor rejects an object without Guzzle's `request()` method.
 * The remaining budget becomes Guzzle's `timeout`/`connect_timeout`, the
 * request body is sent as its stream and the protocol version is kept.
 * `http_errors` is off: a 4xx/5xx response is returned, as with PSR-18.
 * A transport failure after the budget ran out surfaces as
 * `ContextExpiredException` with the transport exception as its cause.
 *
 * @api
 */
final readonly class ContextClient
{
    private OutboundHeaders $outbound;

    public function __construct(
        private object $client,
        ?string $deadlineHeader = null,
        ?string $correlationHeader = null,
    ) {
        if (!method_exists($client, 'request')) {
            throw new \InvalidArgumentException(sprintf('%s has no Guzzle-compatible request() method', $client::class));
        }

        $this->outbound = new OutboundHeaders($deadlineHeader, $correlationHeader);
    }

    public function send(RequestInterface $request, Context $context): ResponseInterface
    {
        $context->assertActive();
        $request = $this->outbound->apply($request, $context);

        $options = [
            'headers' => $request->getHeaders(),
            'body' => $request->getBody(),
            'version' => $request->getProtocolVersion(),
            'http_errors' => false,
        ];
        $remainingMs = $context->remainingMs();
        if ($remainingMs !== null) {
            $options['timeout'] = $remainingMs / 1000.0;
            $options['connect_timeout'] = $remainingMs / 1000.0;
        }

        try {
            $requester = \Closure::fromCallable([$this->client, 'request']);
            /** @var mixed $response */
            $response = $requester($request->getMethod(), (string) $request->getUri(), $options);
        } catch (\Throwable $failure) {
            try {
                $context->assertActive();
            } catch (ContextExpiredException $expired) {
                throw new ContextExpiredException($expired->deadlineNs(), $failure);
            }

            throw $failure;
        }

        $context->assertActive();

        if (!$response instanceof ResponseInterface) {
            throw new \UnexpectedValueException('Guzzle client must return a PSR-7 response');
        }

        return $response;
    }
}
