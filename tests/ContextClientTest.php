<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Tests;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Http\ContextClient;
use Rasuvaeff\Context\Http\CorrelationId;
use Rasuvaeff\Context\Http\Internal\OutboundHeaders;
use Rasuvaeff\Context\Http\Tests\Support\FakeTimeSource;
use Rasuvaeff\Context\Http\Tests\Support\RecordingGuzzle;
use Rasuvaeff\Context\Http\Tests\Support\SteppingTimeSource;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ContextClient::class)]
#[Covers(OutboundHeaders::class)]
final class ContextClientTest
{
    public function mapsTheRemainingBudgetAndKeepsTheRequestIntact(): void
    {
        $guzzle = RecordingGuzzle::responding(new Response(204));
        [$context] = Context::background(new FakeTimeSource())->withTimeout(3.0);
        $body = Utils::streamFor('{"a":1}');
        $request = (new Request('POST', 'https://example.test/x', ['X-Custom' => 'v'], $body))->withProtocolVersion('2');

        $response = (new ContextClient($guzzle))->send($request, $context);

        Assert::same($response->getStatusCode(), 204);
        Assert::same($guzzle->call['method'], 'POST');
        Assert::same($guzzle->call['uri'], 'https://example.test/x');
        Assert::same($guzzle->call['options']['timeout'], 3.0);
        Assert::same($guzzle->call['options']['connect_timeout'], 3.0);
        Assert::same($guzzle->call['options']['body'], $body);
        Assert::same($guzzle->call['options']['version'], '2');
        Assert::same($guzzle->call['options']['http_errors'], expected: false);
        Assert::same($guzzle->call['options']['headers']['X-Custom'], ['v']);
    }

    public function withoutADeadlineNoTimeoutIsSet(): void
    {
        $guzzle = RecordingGuzzle::responding(new Response(200));

        (new ContextClient($guzzle))->send(new Request('GET', 'https://example.test'), Context::background());

        Assert::false(\array_key_exists('timeout', $guzzle->call['options']));
        Assert::false(\array_key_exists('connect_timeout', $guzzle->call['options']));
    }

    public function errorStatusesAreReturnedNotThrown(): void
    {
        $guzzle = RecordingGuzzle::responding(new Response(503));

        Assert::same((new ContextClient($guzzle))->send(new Request('GET', 'https://example.test'), Context::background())->getStatusCode(), 503);
    }

    public function propagatesTheBudgetAndTheCorrelationId(): void
    {
        $guzzle = RecordingGuzzle::responding(new Response(200));
        [$context] = Context::background(new FakeTimeSource())->withTimeout(1.5);
        $context = $context->withValue(CorrelationId::class, new CorrelationId('corr-1'));

        (new ContextClient($guzzle, deadlineHeader: 'X-Request-Timeout-Ms', correlationHeader: 'X-Correlation-ID'))
            ->send(new Request('GET', 'https://example.test'), $context);

        Assert::same($guzzle->call['options']['headers']['X-Request-Timeout-Ms'], ['1500']);
        Assert::same($guzzle->call['options']['headers']['X-Correlation-ID'], ['corr-1']);
    }

    public function anExplicitCorrelationHeaderWins(): void
    {
        $guzzle = RecordingGuzzle::responding(new Response(200));
        $context = Context::background()->withValue(CorrelationId::class, new CorrelationId('from-context'));

        (new ContextClient($guzzle, correlationHeader: 'X-Correlation-ID'))
            ->send(new Request('GET', 'https://example.test', ['X-Correlation-ID' => 'explicit']), $context);

        Assert::same($guzzle->call['options']['headers']['X-Correlation-ID'], ['explicit']);
    }

    public function headersAreOnlyAddedWhenConfigured(): void
    {
        $guzzle = RecordingGuzzle::responding(new Response(200));
        [$context] = Context::background(new FakeTimeSource())->withTimeout(1.0);
        $context = $context->withValue(CorrelationId::class, new CorrelationId('corr-1'));

        (new ContextClient($guzzle))->send(new Request('GET', 'https://example.test'), $context);

        Assert::same(array_keys($guzzle->call['options']['headers']), ['Host']);
    }

    public function aTransportFailureAfterTheDeadlineIsAnExpiry(): void
    {
        $clock = new FakeTimeSource();
        [$context] = Context::background($clock)->withTimeout(1.0);
        $transport = new \RuntimeException('cURL error 28');
        $guzzle = new RecordingGuzzle(static function () use ($clock, $transport): never {
            $clock->advanceMs(1_500);

            throw $transport;
        });

        try {
            (new ContextClient($guzzle))->send(new Request('GET', 'https://example.test'), $context);
        } catch (ContextExpiredException $expired) {
            Assert::same($expired->getPrevious(), $transport);
            Assert::same($expired->deadlineNs(), $context->deadlineNs());

            return;
        }

        throw new \LogicException('an expiry was expected');
    }

    public function aDeadlinePassingDuringSendNeverYieldsAnUnboundedRequest(): void
    {
        // Every clock read moves 0.6 s: the deadline passes between the
        // checks inside send(). curl takes a 0 timeout as "no timeout".
        [$context] = Context::background(new SteppingTimeSource(600_000_000))->withTimeout(1.0);
        $guzzle = RecordingGuzzle::responding(new Response(204));

        try {
            (new ContextClient($guzzle, deadlineHeader: 'X-Request-Timeout-Ms'))
                ->send(new Request('GET', 'https://example.test'), $context);
        } catch (ContextExpiredException) {
            Assert::null($guzzle->call);

            return;
        }

        throw new \LogicException('an expiry was expected, the request was sent');
    }

    public function aTransportFailureWhileActiveIsRethrown(): void
    {
        $transport = new \RuntimeException('connection refused');
        $guzzle = new RecordingGuzzle(static fn(): never => throw $transport);

        try {
            (new ContextClient($guzzle))->send(new Request('GET', 'https://example.test'), Context::background());
        } catch (\RuntimeException $caught) {
            Assert::same($caught, $transport);

            return;
        }

        throw new \LogicException('the transport failure was expected');
    }

    public function aTransportFailureAfterCancellationIsACancellation(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $guzzle = new RecordingGuzzle(static function () use ($controller): never {
            $controller->cancel();

            throw new \RuntimeException('aborted');
        });

        Expect::exception(ContextCancelledException::class);

        (new ContextClient($guzzle))->send(new Request('GET', 'https://example.test'), $context);
    }

    public function aCancelledContextSendsNothing(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $controller->cancel();
        $guzzle = RecordingGuzzle::responding(new Response(200));

        try {
            (new ContextClient($guzzle))->send(new Request('GET', 'https://example.test'), $context);
        } catch (ContextCancelledException) {
            Assert::null($guzzle->call);

            return;
        }

        throw new \LogicException('a cancellation was expected');
    }

    public function rejectsAClientWithoutRequest(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('stdClass has no Guzzle-compatible request() method');

        new ContextClient(new \stdClass());
    }

    public function rejectsANonPsr7Response(): void
    {
        $guzzle = new RecordingGuzzle(static fn(): string => 'nope');

        Expect::exception(\UnexpectedValueException::class)->withMessage('Guzzle client must return a PSR-7 response');

        (new ContextClient($guzzle))->send(new Request('GET', 'https://example.test'), Context::background());
    }

    public function aResponseArrivingAfterCancellationIsNotReturned(): void
    {
        [$context, $controller] = Context::background()->withCancel();
        $guzzle = new RecordingGuzzle(static function () use ($controller): Response {
            $controller->cancel();

            return new Response(200);
        });

        Expect::exception(ContextCancelledException::class);

        (new ContextClient($guzzle))->send(new Request('GET', 'https://example.test'), $context);
    }
}
