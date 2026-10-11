<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Tests\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * Records the Guzzle-style request() call; answers with a response, a
 * throwable, or whatever the closure returns.
 */
final class RecordingGuzzle
{
    /** @var array{method: string, uri: string, options: array<string, mixed>}|null */
    public ?array $call = null;

    /**
     * @param \Closure(): mixed $answer
     */
    public function __construct(private readonly \Closure $answer) {}

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $uri, array $options): mixed
    {
        $this->call = ['method' => $method, 'uri' => $uri, 'options' => $options];

        return ($this->answer)();
    }

    public static function responding(ResponseInterface $response): self
    {
        return new self(static fn(): ResponseInterface => $response);
    }
}
