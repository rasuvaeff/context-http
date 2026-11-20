<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\Http\Internal\OutboundHeaders;
use Rasuvaeff\Context\Http\Internal\TransportFailure;
use Rasuvaeff\Context\WireCodec;

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
 * With `maxTimeout` (seconds) the timeouts never exceed that limit, even
 * when the budget is longer; without a deadline nothing is set either way.
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
        ?WireCodec $wireCodec = null,
        string $baggageHeader = 'baggage',
        private ?float $maxTimeout = null,
    ) {
        if (!method_exists($client, 'request')) {
            throw new \InvalidArgumentException(sprintf('%s has no Guzzle-compatible request() method', $client::class));
        }

        if ($maxTimeout !== null && (!is_finite($maxTimeout) || $maxTimeout <= 0.0)) {
            throw new \InvalidArgumentException('Max timeout must be a finite positive number of seconds');
        }

        $this->outbound = new OutboundHeaders($deadlineHeader, $correlationHeader, $wireCodec, $baggageHeader);
    }

    public function send(RequestInterface $request, Context $context): ResponseInterface
    {
        // Never the 0 of an expired context, which curl would take as
        // "no timeout".
        $budgetMs = $context->budgetMs();
        $request = $this->outbound->apply($request, $context, $budgetMs);

        $options = [
            'headers' => $request->getHeaders(),
            'body' => $request->getBody(),
            'version' => $request->getProtocolVersion(),
            'http_errors' => false,
        ];
        if ($budgetMs !== null) {
            $timeout = (float) $budgetMs / 1000.0;
            if ($this->maxTimeout !== null) {
                $timeout = min($timeout, $this->maxTimeout);
            }
            $options['timeout'] = $timeout;
            $options['connect_timeout'] = $timeout;
        }

        try {
            $requester = \Closure::fromCallable([$this->client, 'request']);
            /** @var mixed $response */
            $response = $requester($request->getMethod(), (string) $request->getUri(), $options);
        } catch (\Throwable $failure) {
            TransportFailure::rethrow($failure, $context);
        }

        $context->assertActive();

        if (!$response instanceof ResponseInterface) {
            throw new \UnexpectedValueException('Guzzle client must return a PSR-7 response');
        }

        return $response;
    }
}
