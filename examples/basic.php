<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\Http\ContextMiddleware;
use Rasuvaeff\Context\Http\CorrelationId;
use Rasuvaeff\Context\Http\PreflightClient;

// A downstream service stand-in: echoes the headers it received.
$downstream = new class implements ClientInterface {
    #[\Override]
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        echo 'downstream budget: ' . $request->getHeaderLine('X-Request-Timeout-Ms') . " ms\n";
        echo 'downstream correlation: ' . $request->getHeaderLine('X-Correlation-ID') . "\n";

        return new Response(200);
    }
};

$handler = new class ($downstream) implements RequestHandlerInterface {
    public function __construct(private readonly ClientInterface $downstream) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $context = $request->getAttribute(Context::class);
        assert($context instanceof Context);

        echo 'correlation id: ' . $request->getAttribute(CorrelationId::class)->value . "\n";

        $client = new PreflightClient(
            $this->downstream,
            $context,
            deadlineHeader: 'X-Request-Timeout-Ms',
            correlationHeader: 'X-Correlation-ID',
        );

        return $client->sendRequest(new Request('GET', 'https://inventory.internal/stock'));
    }
};

$middleware = new ContextMiddleware(
    timeout: 5.0,
    deadlineHeader: 'X-Request-Timeout-Ms',
    responseFactory: new Psr17Factory(),
);

// The upstream caller has 800 ms left and sent its correlation id.
$request = (new ServerRequest('GET', '/orders/42'))
    ->withHeader('X-Request-Timeout-Ms', '800')
    ->withHeader('X-Correlation-ID', 'checkout-7f3a');

$response = $middleware->process($request, $handler);
echo 'status: ' . $response->getStatusCode() . "\n";
