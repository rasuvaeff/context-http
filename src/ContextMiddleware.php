<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http;

use Closure;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextCancelledException;
use Rasuvaeff\Context\ContextController;
use Rasuvaeff\Context\ContextExpiredException;
use Rasuvaeff\Context\Http\Internal\Baggage;
use Rasuvaeff\Context\TimeSource;
use Rasuvaeff\Context\WireCodec;

/**
 * Creates and owns one context for a PSR-15 request.
 *
 * - `deadlineHeader` (e.g. `X-Request-Timeout-Ms`): an incoming budget in
 *   whole milliseconds shortens the configured timeout. It is read by the
 *   same rule as {@see WireCodec::budgetFromWire()}: 1–9 digits, leading
 *   zeros allowed, value 1–999999999; anything else is ignored, never trusted.
 * - The correlation id comes from `correlationIdResolver` when it returns
 *   one, else from the validated correlation header ({@see CorrelationId});
 *   when both are missing a fresh id is generated, so every request has one.
 *   With `correlationResponseHeader` the id is also written to the response
 *   (a header the handler already set wins).
 * - With a `parent`, every request context derives from it (a server's
 *   shutdown context); `timeSource` is then ignored — the parent's clock is
 *   used. Without one each request starts from `Context::background()`.
 * - With a `responseFactory`, a `ContextExpiredException` escaping the
 *   handler becomes `expiredStatus` (504) and a `ContextCancelledException`
 *   becomes `cancelledStatus` (499); without one they propagate.
 * - With a `wireCodec`, allow-listed values are read from the W3C `baggage`
 *   header through `WireValue::fromWire()`; an invalid value is dropped,
 *   never thrown — the header is untrusted input.
 *
 * @api
 */
final readonly class ContextMiddleware implements MiddlewareInterface
{
    /** @var float|Closure(ServerRequestInterface): float */
    private float|Closure $timeout;

    /** @var Closure(): CorrelationId */
    private Closure $correlationIdGenerator;

    /**
     * @param float|callable(ServerRequestInterface): float $timeout
     * @param null|Closure(): CorrelationId $correlationIdGenerator defaults to {@see CorrelationId::generate()}
     * @param null|Closure(ServerRequestInterface): ?CorrelationId $correlationIdResolver
     *        an id the application already has; `null` falls back to the header
     */
    public function __construct(
        float|callable $timeout = 30.0,
        private string $correlationHeader = 'X-Correlation-ID',
        private string $contextAttribute = Context::class,
        private string $controllerAttribute = ContextController::class,
        private string $correlationAttribute = CorrelationId::class,
        private ?TimeSource $timeSource = null,
        private ?string $deadlineHeader = null,
        ?Closure $correlationIdGenerator = null,
        private ?ResponseFactoryInterface $responseFactory = null,
        private int $expiredStatus = 504,
        private int $cancelledStatus = 499,
        private ?WireCodec $wireCodec = null,
        private string $baggageHeader = 'baggage',
        private ?Context $parent = null,
        private ?Closure $correlationIdResolver = null,
        private ?string $correlationResponseHeader = null,
    ) {
        if (is_float($timeout) && !is_finite($timeout)) {
            throw new \InvalidArgumentException('Timeout must be finite');
        }

        foreach (['expiredStatus' => $expiredStatus, 'cancelledStatus' => $cancelledStatus] as $name => $status) {
            if ($status < 100 || $status > 599) {
                throw new \InvalidArgumentException(sprintf('%s must be an HTTP status between 100 and 599, got %d', $name, $status));
            }
        }

        $this->timeout = is_callable($timeout) ? Closure::fromCallable($timeout) : $timeout;
        $this->correlationIdGenerator = $correlationIdGenerator ?? CorrelationId::generate(...);
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $parent = $this->parent ?? Context::background($this->timeSource);
        [$context, $controller] = $parent->withTimeout($this->timeoutFor($request));

        if ($this->wireCodec instanceof WireCodec) {
            // The values share the request context's state: they die with
            // the request controller below.
            $context = $this->wireCodec->decodeValues(Baggage::parse($request->getHeaderLine($this->baggageHeader)), $context);
        }

        $correlationId = $this->correlationIdFor($request);
        $context = $context->withValue(CorrelationId::class, $correlationId);

        $request = $request
            ->withAttribute($this->contextAttribute, $context)
            ->withAttribute($this->controllerAttribute, $controller)
            ->withAttribute($this->correlationAttribute, $correlationId);

        try {
            $response = $handler->handle($request);
        } catch (ContextExpiredException $expired) {
            $response = $this->mapped($expired, $this->expiredStatus);
        } catch (ContextCancelledException $cancelled) {
            $response = $this->mapped($cancelled, $this->cancelledStatus);
        } finally {
            $controller->cancel();
        }

        if ($this->correlationResponseHeader === null || $response->hasHeader($this->correlationResponseHeader)) {
            return $response;
        }

        return $response->withHeader($this->correlationResponseHeader, $correlationId->value);
    }

    private function correlationIdFor(ServerRequestInterface $request): CorrelationId
    {
        $resolved = $this->correlationIdResolver instanceof Closure ? ($this->correlationIdResolver)($request) : null;
        if ($resolved instanceof CorrelationId) {
            return $resolved;
        }

        $incoming = $request->getHeaderLine($this->correlationHeader);

        return CorrelationId::isValid($incoming)
            ? new CorrelationId($incoming)
            : ($this->correlationIdGenerator)();
    }

    private function timeoutFor(ServerRequestInterface $request): float
    {
        $timeout = is_callable($this->timeout)
            ? ($this->timeout)($request)
            : $this->timeout;

        if ($this->deadlineHeader === null) {
            return $timeout;
        }

        $budgetMs = WireCodec::budgetFromWire($request->getHeaderLine($this->deadlineHeader));

        return $budgetMs === null ? $timeout : min($timeout, (float) $budgetMs / 1000.0);
    }

    private function mapped(ContextExpiredException|ContextCancelledException $exception, int $status): ResponseInterface
    {
        if (!$this->responseFactory instanceof ResponseFactoryInterface) {
            throw $exception;
        }

        return $this->responseFactory->createResponse($status);
    }
}
