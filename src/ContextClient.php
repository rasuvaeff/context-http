<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http;

use Closure;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Context\Context;

/**
 * Guzzle adapter with an explicit context argument.
 *
 * The client is intentionally typed as object: Guzzle remains an optional
 * dependency of this package. The object must expose Guzzle's request()
 * method and return a PSR-7 response.
 */
final readonly class ContextClient
{
    public function __construct(private object $client) {}

    public function send(RequestInterface $request, Context $context): ResponseInterface
    {
        $context->assertActive();
        $remainingMs = $context->remainingMs();
        $timeout = $remainingMs === null ? null : $remainingMs / 1000.0;
        $options = [];
        if ($timeout !== null) {
            $options['timeout'] = $timeout;
            $options['connect_timeout'] = $timeout;
        }

        $requester = Closure::fromCallable([$this->client, 'request']);
        $response = $requester(
            $request->getMethod(),
            (string) $request->getUri(),
            $options + ['headers' => $request->getHeaders(), 'body' => (string) $request->getBody()],
        );
        $context->assertActive();

        if (!$response instanceof ResponseInterface) {
            throw new \UnexpectedValueException('Guzzle client must return a PSR-7 response');
        }

        return $response;
    }
}
