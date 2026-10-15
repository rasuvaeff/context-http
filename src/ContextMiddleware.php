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
 *   whole milliseconds (1–999999999) shortens the configured timeout; a
 *   longer or malformed value is ignored, never trusted.
 * - The correlation header is validated ({@see CorrelationId}); when it is
 *   absent or invalid a fresh id is generated, so every request has one.
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
    private const string BUDGET_PATTERN = '/^[1-9][0-9]{0,8}\z/';

    /** @var float|Closure(ServerRequestInterface): float */
    private float|Closure $timeout;

    /** @var Closure(): CorrelationId */
    private Closure $correlationIdGenerator;

    /**
     * @param float|callable(ServerRequestInterface): float $timeout
     * @param null|Closure(): CorrelationId $correlationIdGenerator defaults to {@see CorrelationId::generate()}
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
    ) {
        if (is_float($timeout) && !is_finite($timeout)) {
            throw new \InvalidArgumentException('Timeout must be finite');
        }

        $this->timeout = is_callable($timeout) ? Closure::fromCallable($timeout) : $timeout;
        $this->correlationIdGenerator = $correlationIdGenerator ?? CorrelationId::generate(...);
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        [$context, $controller] = Context::background($this->timeSource)->withTimeout($this->timeoutFor($request));

        if ($this->wireCodec instanceof WireCodec) {
            // The values land on a child that inherits the request deadline
            // (the cap is unlimited, so the parent's deadline wins) and dies
            // with the request controller below.
            [$context] = $this->wireCodec->decode(
                ['values' => Baggage::parse($request->getHeaderLine($this->baggageHeader))],
                $context,
                maxBudgetMs: \PHP_INT_MAX,
            );
        }

        $incoming = $request->getHeaderLine($this->correlationHeader);
        $correlationId = CorrelationId::isValid($incoming)
            ? new CorrelationId($incoming)
            : ($this->correlationIdGenerator)();
        $context = $context->withValue(CorrelationId::class, $correlationId);

        $request = $request
            ->withAttribute($this->contextAttribute, $context)
            ->withAttribute($this->controllerAttribute, $controller)
            ->withAttribute($this->correlationAttribute, $correlationId);

        try {
            return $handler->handle($request);
        } catch (ContextExpiredException $expired) {
            return $this->mapped($expired, $this->expiredStatus);
        } catch (ContextCancelledException $cancelled) {
            return $this->mapped($cancelled, $this->cancelledStatus);
        } finally {
            $controller->cancel();
        }
    }

    private function timeoutFor(ServerRequestInterface $request): float
    {
        $timeout = is_callable($this->timeout)
            ? ($this->timeout)($request)
            : $this->timeout;

        if ($this->deadlineHeader === null) {
            return $timeout;
        }

        $budget = $request->getHeaderLine($this->deadlineHeader);
        if (preg_match(self::BUDGET_PATTERN, $budget) !== 1) {
            return $timeout;
        }

        return min($timeout, (float) $budget / 1000.0);
    }

    private function mapped(ContextExpiredException|ContextCancelledException $exception, int $status): ResponseInterface
    {
        if (!$this->responseFactory instanceof ResponseFactoryInterface) {
            throw $exception;
        }

        return $this->responseFactory->createResponse($status);
    }
}
