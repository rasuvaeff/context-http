<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Tests;

use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\Http\PreflightClient;
use Testo\Assert;
use Testo\Expect;
use Testo\Test;

#[Test]
final class PreflightClientTest
{
    public function checksBeforeAndAfterRequest(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $inner = new class implements ClientInterface {
            public int $calls = 0;

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ++$this->calls;

                return new Response(200);
            }
        };
        $client = new PreflightClient($inner, $context);

        $response = $client->sendRequest(new Request('GET', '/'));
        Assert::same($response->getStatusCode(), 200);
        Assert::same($inner->calls, 1);

        $controller->cancel();
        Expect::exception(\Rasuvaeff\Context\ContextCancelledException::class);
        $client->sendRequest(new Request('GET', '/'));
        Assert::same($inner->calls, 1);
    }
}
