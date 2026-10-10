<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Tests;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextController;
use Rasuvaeff\Context\Http\ContextMiddleware;
use Rasuvaeff\Context\Http\CorrelationId;
use Testo\Assert;
use Testo\Test;

#[Test]
final class ContextMiddlewareTest
{
    public function publishesAttributesAndCancelsAfterHandler(): void
    {
        $request = new ServerRequest('GET', '/')->withHeader('X-Correlation-ID', 'abc');
        $middleware = new ContextMiddleware(timeout: 1.0);
        $handler = new class implements RequestHandlerInterface {
            public bool $terminated = false;

            #[\Override]
            public function handle(ServerRequestInterface $request): Response
            {
                $context = $request->getAttribute(Context::class);
                $controller = $request->getAttribute(ContextController::class);
                Assert::true($context instanceof Context);
                Assert::true($controller instanceof ContextController);
                Assert::same($request->getAttribute(CorrelationId::class)->value, 'abc');
                $context->onTerminate(function (): void {
                    $this->terminated = true;
                });

                return new Response(204);
            }
        };

        $response = $middleware->process($request, $handler);

        Assert::same($response->getStatusCode(), 204);
        Assert::true($handler->terminated);
    }
}
