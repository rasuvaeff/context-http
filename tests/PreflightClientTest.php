<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Tests;

use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Context\ContextController;
use Rasuvaeff\Context\Http\CorrelationId;
use Rasuvaeff\Context\Http\Internal\OutboundHeaders;
use Rasuvaeff\Context\Http\PreflightClient;
use Rasuvaeff\Context\Http\Tests\Support\FakeTimeSource;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(PreflightClient::class)]
#[Covers(OutboundHeaders::class)]
final class PreflightClientTest
{
    public function checksBeforeAndAfterRequest(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $inner = $this->recordingClient();
        $client = new PreflightClient($inner, $context);

        $response = $client->sendRequest(new Request('GET', '/'));
        Assert::same($response->getStatusCode(), 200);
        Assert::same($inner->calls, 1);

        $controller->cancel();

        try {
            $client->sendRequest(new Request('GET', '/'));
        } catch (ContextCancelledException) {
            Assert::same($inner->calls, 1);

            return;
        }

        throw new \LogicException('a cancellation was expected');
    }

    public function aResponseArrivingAfterCancellationIsNotReturned(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $inner = new readonly class ($controller) implements ClientInterface {
            public function __construct(private ContextController $controller) {}

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->controller->cancel();

                return new Response(200);
            }
        };

        Expect::exception(ContextCancelledException::class);

        (new PreflightClient($inner, $context))->sendRequest(new Request('GET', '/'));
    }

    public function propagatesTheBudgetAndTheCorrelationId(): void
    {
        [$context] = Context::background(new FakeTimeSource())->withTimeout(0.25);
        $context = $context->withValue(CorrelationId::class, new CorrelationId('corr-2'));
        $inner = $this->recordingClient();

        (new PreflightClient($inner, $context, deadlineHeader: 'X-Request-Timeout-Ms', correlationHeader: 'X-Correlation-ID'))
            ->sendRequest(new Request('GET', '/'));

        Assert::same($inner->request?->getHeaderLine('X-Request-Timeout-Ms'), '250');
        Assert::same($inner->request?->getHeaderLine('X-Correlation-ID'), 'corr-2');
    }

    public function sendsTheRequestUnchangedByDefault(): void
    {
        [$context] = Context::background(new FakeTimeSource())->withTimeout(1.0);
        $inner = $this->recordingClient();
        $request = new Request('GET', '/');

        (new PreflightClient($inner, $context))->sendRequest($request);

        Assert::same($inner->request, $request);
    }

    public function noBudgetHeaderWithoutADeadline(): void
    {
        $inner = $this->recordingClient();

        (new PreflightClient($inner, Context::background(), deadlineHeader: 'X-Request-Timeout-Ms'))
            ->sendRequest(new Request('GET', '/'));

        Assert::false($inner->request?->hasHeader('X-Request-Timeout-Ms'));
    }

    /**
     * @return ClientInterface&object{calls: int, request: ?RequestInterface}
     */
    private function recordingClient(): ClientInterface
    {
        return new class implements ClientInterface {
            public int $calls = 0;

            public ?RequestInterface $request = null;

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                ++$this->calls;
                $this->request = $request;

                return new Response(200);
            }
        };
    }
}
