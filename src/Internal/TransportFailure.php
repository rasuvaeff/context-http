<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Internal;

use Rasuvaeff\Context\Context;
use Rasuvaeff\Context\ContextExpiredException;

/**
 * What a transport failure means once the context is checked again.
 *
 * @internal
 */
final readonly class TransportFailure
{
    /**
     * An expired context turns the failure into its `ContextExpiredException`
     * (the failure as `previous`), a cancelled one throws its own
     * cancellation; an active one rethrows the failure untouched.
     *
     * @throws \Throwable
     */
    public static function rethrow(\Throwable $failure, Context $context): never
    {
        try {
            $context->assertActive();
        } catch (ContextExpiredException $expired) {
            throw new ContextExpiredException($expired->deadlineNs(), $failure);
        }

        throw $failure;
    }
}
