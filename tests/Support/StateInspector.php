<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Tests\Support;

use Rasuvaeff\Context\Context;

/**
 * Reads the core's internal state graph (as the core's own tests do) to prove
 * that a request context is detached from a long-lived parent.
 */
final class StateInspector
{
    public static function childCount(Context $context): int
    {
        $state = (new \ReflectionProperty(Context::class, 'state'))->getValue($context);
        if (!\is_object($state)) {
            throw new \LogicException('Context has no state');
        }

        $children = (new \ReflectionProperty($state, 'children'))->getValue($state);

        return \is_array($children) ? \count($children) : 0;
    }
}
