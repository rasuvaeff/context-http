<?php

declare(strict_types=1);

namespace Rasuvaeff\Context\Http\Tests\Support;

use Rasuvaeff\Context\Context;

/**
 * Reads the core's internal state graph (as the core's own tests do) to prove
 * that a request context is detached from a long-lived parent. It throws when
 * the core's layout changes instead of counting zero, so a refactor of the
 * core cannot turn the cleanup tests silently green.
 */
final readonly class StateInspector
{
    public static function childCount(Context $context): int
    {
        $state = (new \ReflectionProperty(Context::class, 'state'))->getValue($context);
        if (!\is_object($state)) {
            throw new \LogicException('Context has no state object');
        }

        $children = (new \ReflectionProperty($state, 'children'))->getValue($state);
        if (!\is_array($children)) {
            throw new \LogicException('The core context state no longer keeps its children in an array');
        }

        return \count($children);
    }
}
