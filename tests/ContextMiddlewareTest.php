<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Context\ContextController;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Http\ContextMiddleware;
use Rasuvaeff\Context\Http\CorrelationId;
use Rasuvaeff\Context\Http\Tests\Support\FakeTimeSource;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ContextMiddleware::class)]
#[Covers(CorrelationId::class)]
final class ContextMiddlewareTest
{
    public function publishesAttributesAndCancelsAfterHandler(): void
    {
        $request = (new ServerRequest('GET', '/'))->withHeader('X-Correlation-ID', 'abc');
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
                Assert::same($context->value(CorrelationId::class)?->value, 'abc');
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

    #[DataProvider('missingOrInvalidCorrelationProvider')]
    public function generatesACorrelationIdWhenTheHeaderIsMissingOrInvalid(?string $header): void
    {
        $request = new ServerRequest('GET', '/');
        if ($header !== null) {
            $request = $request->withHeader('X-Correlation-ID', $header);
        }
        $middleware = new ContextMiddleware(
            timeout: 1.0,
            correlationIdGenerator: static fn(): CorrelationId => new CorrelationId('generated'),
        );
        $handler = $this->recordingHandler();

        $middleware->process($request, $handler);

        Assert::same($handler->request?->getAttribute(CorrelationId::class)?->value, 'generated');
    }

    public static function missingOrInvalidCorrelationProvider(): iterable
    {
        yield 'missing' => [null];
        yield 'forbidden character' => ['abc def'];
        yield 'too long' => [str_repeat('a', 129)];
    }

    public function theDefaultGeneratorProducesARandomId(): void
    {
        $handler = $this->recordingHandler();

        (new ContextMiddleware(timeout: 1.0))->process(new ServerRequest('GET', '/'), $handler);

        $id = $handler->request?->getAttribute(CorrelationId::class);
        Assert::true($id instanceof CorrelationId);
        Assert::same(\strlen($id->value), 32);
    }

    #[DataProvider('deadlineHeaderProvider')]
    public function anIncomingBudgetOnlyShortensTheTimeout(string $header, float $expectedMs): void
    {
        $request = (new ServerRequest('GET', '/'))->withHeader('X-Request-Timeout-Ms', $header);
        $middleware = new ContextMiddleware(
            timeout: 2.0,
            timeSource: new FakeTimeSource(),
            deadlineHeader: 'X-Request-Timeout-Ms',
        );
        $handler = $this->recordingHandler();

        $middleware->process($request, $handler);

        Assert::same($handler->remainingMs, $expectedMs);
    }

    public static function deadlineHeaderProvider(): iterable
    {
        yield 'shorter budget wins' => ['750', 750.0];
        yield 'longer budget is capped' => ['5000', 2000.0];
        yield 'zero is ignored' => ['0', 2000.0];
        yield 'negative is ignored' => ['-5', 2000.0];
        yield 'not a number' => ['abc', 2000.0];
        yield 'exponent' => ['1e3', 2000.0];
        yield 'fraction' => ['1.5', 2000.0];
        yield 'trailing newline' => ["750\n", 2000.0];
        yield 'out of range' => ['1000000000', 2000.0];
        yield 'empty' => ['', 2000.0];
    }

    public function theBudgetHeaderIsIgnoredUnlessConfigured(): void
    {
        $request = (new ServerRequest('GET', '/'))->withHeader('X-Request-Timeout-Ms', '100');
        $handler = $this->recordingHandler();

        (new ContextMiddleware(timeout: 2.0, timeSource: new FakeTimeSource()))->process($request, $handler);

        Assert::same($handler->remainingMs, 2000.0);
    }

    public function aCallableTimeoutIsAlsoCappedByTheBudget(): void
    {
        $request = (new ServerRequest('GET', '/'))->withHeader('X-Request-Timeout-Ms', '300');
        $handler = $this->recordingHandler();

        (new ContextMiddleware(
            timeout: static fn(ServerRequestInterface $request): float => 1.0,
            timeSource: new FakeTimeSource(),
            deadlineHeader: 'X-Request-Timeout-Ms',
        ))->process($request, $handler);

        Assert::same($handler->remainingMs, 300.0);
    }

    #[DataProvider('mappedExceptionProvider')]
    public function contextExceptionsMapToResponsesWhenAFactoryIsGiven(\Throwable $thrown, int $expectedStatus): void
    {
        $middleware = new ContextMiddleware(timeout: 1.0, responseFactory: new Psr17Factory());

        $response = $middleware->process(new ServerRequest('GET', '/'), $this->throwingHandler($thrown));

        Assert::same($response->getStatusCode(), $expectedStatus);
    }

    public static function mappedExceptionProvider(): iterable
    {
        yield 'expired' => [new ContextExpiredException(1), 504];
        yield 'cancelled' => [new ContextCancelledException(), 499];
    }

    public function mappedStatusesAreConfigurable(): void
    {
        $middleware = new ContextMiddleware(
            timeout: 1.0,
            responseFactory: new Psr17Factory(),
            expiredStatus: 503,
            cancelledStatus: 408,
        );

        Assert::same($middleware->process(new ServerRequest('GET', '/'), $this->throwingHandler(new ContextExpiredException(1)))->getStatusCode(), 503);
        Assert::same($middleware->process(new ServerRequest('GET', '/'), $this->throwingHandler(new ContextCancelledException()))->getStatusCode(), 408);
    }

    public function withoutAFactoryContextExceptionsPropagate(): void
    {
        $expired = new ContextExpiredException(1);

        try {
            (new ContextMiddleware(timeout: 1.0))->process(new ServerRequest('GET', '/'), $this->throwingHandler($expired));
        } catch (ContextExpiredException $caught) {
            Assert::same($caught, $expired);

            return;
        }

        throw new \LogicException('the exception was expected to propagate');
    }

    public function otherExceptionsAlwaysPropagate(): void
    {
        Expect::exception(\RuntimeException::class)->withMessage('boom');

        (new ContextMiddleware(timeout: 1.0, responseFactory: new Psr17Factory()))
            ->process(new ServerRequest('GET', '/'), $this->throwingHandler(new \RuntimeException('boom')));
    }

    public function theContextIsCancelledEvenWhenTheHandlerThrows(): void
    {
        $captured = null;
        $handler = new class ($captured) implements RequestHandlerInterface {
            public function __construct(public ?Context &$context) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->context = $request->getAttribute(Context::class);

                throw new ContextExpiredException(1);
            }
        };

        (new ContextMiddleware(timeout: 1.0, responseFactory: new Psr17Factory()))->process(new ServerRequest('GET', '/'), $handler);

        Assert::true($captured instanceof Context && $captured->isCancelled());
    }

    public function rejectsANonFiniteTimeout(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Timeout must be finite');

        new ContextMiddleware(timeout: \INF);
    }

    /**
     * @return RequestHandlerInterface&object{request: ?ServerRequestInterface, remainingMs: ?float}
     */
    private function recordingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            public ?float $remainingMs = null;

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                $context = $request->getAttribute(Context::class);
                $this->remainingMs = $context instanceof Context ? $context->remainingMs() : null;

                return new Response(204);
            }
        };
    }

    private function throwingHandler(\Throwable $thrown): RequestHandlerInterface
    {
        return new readonly class ($thrown) implements RequestHandlerInterface {
            public function __construct(private \Throwable $thrown) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->thrown;
            }
        };
    }
}
