<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextController;
use Rasuvaeff\Context\TimeSource;

/** Creates and owns one context for a PSR-15 request. */
final readonly class ContextMiddleware implements MiddlewareInterface
{
    /** @var float|Closure(ServerRequestInterface): float */
    private float|Closure $timeout;

    /**
     * @param float|callable(ServerRequestInterface): float $timeout
     */
    public function __construct(
        float|callable $timeout = 30.0,
        private string $correlationHeader = 'X-Correlation-ID',
        private string $contextAttribute = Context::class,
        private string $controllerAttribute = ContextController::class,
        private string $correlationAttribute = CorrelationId::class,
        private ?TimeSource $timeSource = null,
    ) {
        if (is_float($timeout) && !is_finite($timeout)) {
            throw new \InvalidArgumentException('Timeout must be finite');
        }

        $this->timeout = is_callable($timeout) ? Closure::fromCallable($timeout) : $timeout;
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $timeout = is_callable($this->timeout)
            ? ($this->timeout)($request)
            : $this->timeout;
        [$context, $controller] = Context::background($this->timeSource)->withTimeout($timeout);

        $correlationId = $request->getHeaderLine($this->correlationHeader);
        $correlationValue = null;
        if ($correlationId !== '') {
            $correlationValue = new CorrelationId($correlationId);
            $context = $context->withValue(CorrelationId::class, $correlationValue);
        }

        $request = $request
            ->withAttribute($this->contextAttribute, $context)
            ->withAttribute($this->controllerAttribute, $controller);
        if ($correlationId !== '') {
            $request = $request->withAttribute(
                $this->correlationAttribute,
                $correlationValue,
            );
        }

        try {
            return $handler->handle($request);
        } finally {
            $controller->cancel();
        }
    }
}
