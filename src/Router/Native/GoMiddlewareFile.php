<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Native;

/**
 * What the framework reads out of a `src/Pages/middleware.go` without a
 * Go toolchain: whether it declares the one function the proxy protocol
 * needs.
 *
 * The Go counterpart of `middleware.php` is written like the Next.js
 * prototype's `proxy.go`: `package main` with a single
 *
 *   func Proxy(w http.ResponseWriter, r *http.Request)
 *
 * and no `main()` — `relayer native:build` generates that
 * ({@see GoEntrypoint::renderProxy()}). Continuing to the route is a
 * header the function sets (`Relayer-Next: 1`), see {@see NativeProxy}.
 */
final class GoMiddlewareFile
{
    public const NAME = 'middleware.go';

    /** The function a middleware.go must export. */
    public const HANDLER = 'Proxy';

    private const PROXY_PATTERN = '/^func Proxy\s*\(/m';

    public static function declaresProxy(string $source): bool
    {
        return 1 === \preg_match(self::PROXY_PATTERN, GoSource::stripComments($source));
    }

    public static function isMainPackage(string $source): bool
    {
        return GoSource::isMainPackage($source);
    }
}
