<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\Http\ContextClient;
use Testo\Assert;
use Testo\Test;

#[Test]
final class ContextClientTest
{
    public function mapsRemainingBudgetToGuzzleOptions(): void
    {
        $client = new class {
            /** @var array{method: string, uri: string, options: array<string, mixed>}|null */
            public ?array $call = null;

            public function request(string $method, string $uri, array $options): Response
            {
                $this->call = ['method' => $method, 'uri' => $uri, 'options' => $options];

                return new Response(204);
            }
        };
        [$context] = Context::background()->withTimeout(3.0);

        $response = (new ContextClient($client))->send(new Request('POST', 'https://example.test'), $context);

        Assert::same($response->getStatusCode(), 204);
        Assert::same($client->call['method'], 'POST');
        Assert::true($client->call['options']['timeout'] <= 3.0);
        Assert::same($client->call['options']['connect_timeout'], $client->call['options']['timeout']);
    }
}
