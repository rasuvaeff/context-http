<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Tests;

use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Context\ContextController;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Http\CorrelationId;
use Rasuvaeff\Context\Http\Internal\OutboundHeaders;
use Rasuvaeff\Context\Http\Internal\TransportFailure;
use Rasuvaeff\Context\Http\PreflightClient;
use Rasuvaeff\Context\Testing\FakeTimeSource;
use Rasuvaeff\Context\Testing\SteppingTimeSource;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(PreflightClient::class)]
#[Covers(OutboundHeaders::class)]
#[Covers(TransportFailure::class)]
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

    public function aDeadlinePassingBeforeSendingSendsNothing(): void
    {
        // Every clock read moves 0.6 s: the deadline passes between the
        // check and the budget header, which would otherwise carry "0".
        [$context] = Context::background(new SteppingTimeSource(600_000_000))->withTimeout(1.0);
        $inner = $this->recordingClient();

        try {
            (new PreflightClient($inner, $context, deadlineHeader: 'X-Request-Timeout-Ms'))
                ->sendRequest(new Request('GET', '/'));
        } catch (ContextExpiredException) {
            Assert::same($inner->calls, 0);

            return;
        }

        throw new \LogicException('an expiry was expected, the request was sent');
    }

    public function withContextKeepsTheConfigurationAndChecksTheNewContext(): void
    {
        $inner = $this->recordingClient();
        [$first, $firstController] = Context::background(new FakeTimeSource())->withTimeout(1.0);
        $client = new PreflightClient($inner, $first, deadlineHeader: 'X-Request-Timeout-Ms');
        [$second] = Context::background(new FakeTimeSource())->withTimeout(0.25);
        $firstController->cancel();

        $client->withContext($second)->sendRequest(new Request('GET', '/'));

        Assert::same($inner->request?->getHeaderLine('X-Request-Timeout-Ms'), '250');
    }

    public function aTransportFailureAfterTheDeadlineStaysAsIsByDefault(): void
    {
        $failure = $this->clientException();
        [$context, $clock] = $this->expiringContext();

        try {
            (new PreflightClient($this->failingClient($failure, $clock), $context))->sendRequest(new Request('GET', '/'));
        } catch (ClientExceptionInterface $caught) {
            Assert::same($caught, $failure);

            return;
        }

        throw new \LogicException('the transport failure was expected');
    }

    public function aTransportFailureAfterTheDeadlineIsAnExpiryWhenEnabled(): void
    {
        $failure = $this->clientException();
        [$context, $clock] = $this->expiringContext();
        $client = new PreflightClient($this->failingClient($failure, $clock), $context, expireTransportFailures: true);

        try {
            $client->sendRequest(new Request('GET', '/'));
        } catch (ContextExpiredException $expired) {
            Assert::same($expired->getPrevious(), $failure);
            Assert::same($expired->deadlineNs(), $context->deadlineNs());

            return;
        }

        throw new \LogicException('an expiry was expected');
    }

    public function aTransportFailureWhileActiveIsRethrownWhenEnabled(): void
    {
        $failure = $this->clientException();
        [$context] = Context::background(new FakeTimeSource())->withTimeout(1.0);
        $client = new PreflightClient($this->failingClient($failure, null), $context, expireTransportFailures: true);

        try {
            $client->sendRequest(new Request('GET', '/'));
        } catch (ClientExceptionInterface $caught) {
            Assert::same($caught, $failure);

            return;
        }

        throw new \LogicException('the transport failure was expected');
    }

    public function withContextKeepsTheExpiryMapping(): void
    {
        $failure = $this->clientException();
        [$context, $clock] = $this->expiringContext();
        $client = (new PreflightClient($this->failingClient($failure, $clock), Context::background(), expireTransportFailures: true))
            ->withContext($context);

        Expect::exception(ContextExpiredException::class);

        $client->sendRequest(new Request('GET', '/'));
    }

    /** @return array{Context, FakeTimeSource} */
    private function expiringContext(): array
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withTimeout(1.0);

        return [$context, $clock];
    }

    private function clientException(): ClientExceptionInterface
    {
        return new class ('timed out') extends \RuntimeException implements ClientExceptionInterface {};
    }

    private function failingClient(ClientExceptionInterface $failure, ?FakeTimeSource $clock): ClientInterface
    {
        return new readonly class ($failure, $clock) implements ClientInterface {
            public function __construct(private ClientExceptionInterface $failure, private ?FakeTimeSource $clock) {}

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->clock?->advanceMs(1_500);

                throw $this->failure;
            }
        };
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
