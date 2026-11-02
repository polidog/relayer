<?php

declare(strict_types=1);

namespace Polidog\Relayer;

use Closure;
use Polidog\Rosanna\Runtime\RequestLifecycle;
use WeakMap;

/**
 * Every live object that remembers something about the current request, so
 * {@see RequestLifecycle::reset()} can make all of them forget it.
 *
 * Rosanna's registry keeps one callback per name, so a class that registers
 * its own instance under its class name only ever resets the newest one: with
 * two instances alive (two containers, a non-shared service) the older one
 * keeps the previous visitor's identity or memo. This holds each instance
 * once, under a single Rosanna entry.
 *
 * Instances are weak keys: one the app has dropped is not kept alive here.
 * Pass a `static` closure that takes the instance as its argument; a closure
 * bound to `$this` would hold the instance through the value.
 *
 * @internal
 */
final class RequestScopedInstances
{
    /** @var null|WeakMap<object, Closure> */
    private static ?WeakMap $instances = null;

    /**
     * @template T of object
     *
     * @param T                $instance
     * @param Closure(T): void $reset    called on every request reset while $instance is alive
     */
    public static function track(object $instance, Closure $reset): void
    {
        self::$instances ??= new WeakMap();
        self::$instances[$instance] = $reset;

        // Rosanna replaces an entry of the same name, so this stays one entry
        // however many instances are tracked.
        RequestLifecycle::onReset(self::class, self::resetAll(...));
    }

    private static function resetAll(): void
    {
        foreach (self::$instances ?? [] as $instance => $reset) {
            $reset($instance);
        }
    }
}
