<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Tests;

use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Nyholm\Psr7\Request;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\Http\ContextClient;
use Rasuvaeff\Context\Http\ContextMiddleware;
use Rasuvaeff\Context\Http\CorrelationId;
use Rasuvaeff\Context\Http\Internal\OutboundHeaders;
use Rasuvaeff\Context\Http\PreflightClient;
use Rasuvaeff\Context\Http\Tests\Support\FakeTimeSource;
use Rasuvaeff\Context\Http\Tests\Support\Note;
use Rasuvaeff\Context\Http\Tests\Support\RecordingGuzzle;
use Rasuvaeff\Context\Http\Tests\Support\TenantId;
use Rasuvaeff\Context\WireCodec;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(ContextMiddleware::class)]
#[Covers(ContextClient::class)]
#[Covers(PreflightClient::class)]
#[Covers(OutboundHeaders::class)]
final class BaggagePropagationTest
{
    public function theMiddlewareReadsAllowListedValuesFromBaggage(): void
    {
        $handler = $this->contextHandler();
        $request = (new ServerRequest('GET', '/'))
            ->withHeader('baggage', 'tenant=acme, note=a%2Cb, userId=alice');

        (new ContextMiddleware(timeout: 2.0, wireCodec: $this->codec()))->process($request, $handler);

        Assert::same($handler->context?->value(TenantId::class)?->value, 'acme');
        Assert::same($handler->context?->value(Note::class)?->value, 'a,b');
        Assert::true($handler->context?->value(CorrelationId::class) instanceof CorrelationId);
    }

    public function anInvalidIncomingValueIsDroppedNotThrown(): void
    {
        $handler = $this->contextHandler();
        $request = (new ServerRequest('GET', '/'))->withHeader('baggage', 'tenant=NOT%20VALID,note=kept');

        (new ContextMiddleware(timeout: 2.0, wireCodec: $this->codec()))->process($request, $handler);

        Assert::null($handler->context?->value(TenantId::class));
        Assert::same($handler->context?->value(Note::class)?->value, 'kept');
    }

    public function withoutACodecBaggageIsIgnored(): void
    {
        $handler = $this->contextHandler();
        $request = (new ServerRequest('GET', '/'))->withHeader('baggage', 'tenant=acme');

        (new ContextMiddleware(timeout: 2.0))->process($request, $handler);

        Assert::null($handler->context?->value(TenantId::class));
    }

    public function baggageNeverChangesTheBudget(): void
    {
        $handler = $this->contextHandler();
        $request = (new ServerRequest('GET', '/'))->withHeader('baggage', 'tenant=acme');

        (new ContextMiddleware(timeout: 2.0, timeSource: new FakeTimeSource(), wireCodec: $this->codec()))->process($request, $handler);

        Assert::same($handler->remainingMs, 2_000.0);
    }

    public function theRequestControllerCancelsTheContextCarryingTheValues(): void
    {
        $handler = $this->contextHandler();
        $request = (new ServerRequest('GET', '/'))->withHeader('baggage', 'tenant=acme');

        (new ContextMiddleware(timeout: 2.0, wireCodec: $this->codec()))->process($request, $handler);

        Assert::true($handler->context?->isCancelled());
    }

    public function contextClientAddsBaggageAndKeepsOtherMembers(): void
    {
        $guzzle = RecordingGuzzle::responding(new GuzzleResponse(204));
        $context = $this->contextWithValues();
        $request = (new GuzzleRequest('GET', 'https://internal.test'))->withHeader('baggage', 'sentry-trace_id=abc,tenant=theirs');

        (new ContextClient($guzzle, wireCodec: $this->codec()))->send($request, $context);

        Assert::same($guzzle->call['options']['headers']['baggage'] ?? null, ['sentry-trace_id=abc,tenant=theirs,note=a%2Cb']);
    }

    public function withoutACodecTheClientSendsNoBaggage(): void
    {
        $guzzle = RecordingGuzzle::responding(new GuzzleResponse(204));

        (new ContextClient($guzzle))->send(new GuzzleRequest('GET', 'https://api.stripe.test'), $this->contextWithValues());

        Assert::false(\array_key_exists('baggage', $guzzle->call['options']['headers'] ?? []));
    }

    public function aContextWithoutValuesSendsNoBaggage(): void
    {
        $inner = $this->recordingClient();
        [$context] = Context::background()->withCancel();

        (new PreflightClient($inner, $context, wireCodec: $this->codec()))->sendRequest(new Request('GET', '/'));

        Assert::false($inner->request?->hasHeader('baggage'));
    }

    public function preflightClientAddsBaggageUnderACustomHeaderName(): void
    {
        $inner = $this->recordingClient();

        (new PreflightClient($inner, $this->contextWithValues(), wireCodec: $this->codec(), baggageHeader: 'X-Baggage'))
            ->sendRequest(new Request('GET', '/'));

        Assert::same($inner->request?->getHeaderLine('X-Baggage'), 'tenant=acme,note=a%2Cb');
        Assert::false($inner->request?->hasHeader('baggage'));
    }

    public function valuesAndTheBudgetCrossTwoServices(): void
    {
        $clock = new FakeTimeSource();
        $codec = $this->codec();
        $downstream = $this->contextHandler();
        $downstreamMiddleware = new ContextMiddleware(
            timeout: 30.0,
            timeSource: $clock,
            deadlineHeader: 'X-Request-Timeout-Ms',
            wireCodec: $codec,
        );

        // Service A: the incoming request carries the tenant; its handler
        // calls service B through a PSR-18 client.
        $upstream = new readonly class ($codec, $downstreamMiddleware, $downstream, $clock) implements RequestHandlerInterface {
            public function __construct(
                private WireCodec $codec,
                private ContextMiddleware $next,
                private RequestHandlerInterface $nextHandler,
                private FakeTimeSource $clock,
            ) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $context = $request->getAttribute(Context::class);
                \assert($context instanceof Context);
                $this->clock->advanceMs(200);
                $network = new readonly class ($this->next, $this->nextHandler) implements ClientInterface {
                    public function __construct(private ContextMiddleware $next, private RequestHandlerInterface $handler) {}

                    #[\Override]
                    public function sendRequest(RequestInterface $request): ResponseInterface
                    {
                        $server = new ServerRequest($request->getMethod(), $request->getUri(), $request->getHeaders());

                        return $this->next->process($server, $this->handler);
                    }
                };

                return (new PreflightClient($network, $context, deadlineHeader: 'X-Request-Timeout-Ms', wireCodec: $this->codec))
                    ->sendRequest(new Request('GET', 'http://service-b.internal/'));
            }
        };

        (new ContextMiddleware(timeout: 1.0, timeSource: $clock, wireCodec: $codec))
            ->process((new ServerRequest('GET', '/'))->withHeader('baggage', 'tenant=acme'), $upstream);

        Assert::same($downstream->context?->value(TenantId::class)?->value, 'acme');
        Assert::same($downstream->remainingMs, 800.0);
    }

    private function codec(): WireCodec
    {
        return new WireCodec(['tenant' => TenantId::class, 'note' => Note::class]);
    }

    private function contextWithValues(): Context
    {
        [$context] = Context::background()->withCancel();

        return $context
            ->withValue(TenantId::class, new TenantId('acme'))
            ->withValue(Note::class, new Note('a,b'));
    }

    /**
     * @return RequestHandlerInterface&object{context: ?Context, remainingMs: ?float}
     */
    private function contextHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public ?Context $context = null;

            public ?float $remainingMs = null;

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $context = $request->getAttribute(Context::class);
                $this->context = $context instanceof Context ? $context : null;
                $this->remainingMs = $this->context?->remainingMs();

                return new Response(204);
            }
        };
    }

    /**
     * @return ClientInterface&object{request: ?RequestInterface}
     */
    private function recordingClient(): ClientInterface
    {
        return new class implements ClientInterface {
            public ?RequestInterface $request = null;

            #[\Override]
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $this->request = $request;

                return new Response(204);
            }
        };
    }
}
