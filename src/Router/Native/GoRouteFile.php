<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Native;

/**
 * What the framework reads out of a `route.go` *without* a Go toolchain:
 * which HTTP methods it declares and whether it is `package main`.
 *
 * A native route file is written the way the Next.js prototype has it —
 * no `main()`, just top-level handlers named after HTTP methods:
 *
 *   package main
 *
 *   func GET(w http.ResponseWriter, r *http.Request) { ... }
 *
 * `relayer native:build` generates the `main()` around them
 * ({@see GoEntrypoint}), and `relayer routes` lists them before anything
 * is built — both from this one scanner, so the two can never disagree
 * about what a file declares. Pure text matching (`^func GET(`), which is
 * all the contract needs: a handler is a top-level function with exactly
 * the method's name.
 */
final class GoRouteFile
{
    public const NAME = 'route.go';

    /** The method names a `route.go` may declare handlers for. */
    public const HANDLER_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    private const HANDLER_PATTERN = '/^func (GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\s*\(/m';

    private const PACKAGE_MAIN_PATTERN = '/^package\s+main\b/m';

    /**
     * The handler methods `$source` declares, sorted. Empty when it
     * declares none (a build error, surfaced by the build command).
     *
     * @return list<string>
     */
    public static function declaredMethods(string $source): array
    {
        if (1 > \preg_match_all(self::HANDLER_PATTERN, self::stripComments($source), $matches)) {
            return [];
        }

        $methods = \array_values(\array_unique($matches[1]));
        \sort($methods);

        return $methods;
    }

    /**
     * Every method a route with `$declared` handlers answers, sorted —
     * the declared ones plus the `OPTIONS` (always) and `HEAD` (when `GET`
     * exists) the generated entrypoint synthesizes. Same rule as the PHP
     * `route.php` dispatcher, so an `Allow` header reads the same for both.
     *
     * @param list<string> $declared
     *
     * @return list<string>
     */
    public static function effectiveAllowedMethods(array $declared): array
    {
        $methods = $declared;
        $methods[] = 'OPTIONS';
        if (\in_array('GET', $declared, true)) {
            $methods[] = 'HEAD';
        }
        $methods = \array_values(\array_unique($methods));
        \sort($methods);

        return $methods;
    }

    public static function isMainPackage(string $source): bool
    {
        return 1 === \preg_match(self::PACKAGE_MAIN_PATTERN, self::stripComments($source));
    }

    /**
     * Drop line and block comments so a commented-out handler is not
     * mistaken for a declared one. Deliberately does not parse string
     * literals: a `"func GET("` inside a string is contrived enough not
     * to pay a tokenizer for.
     */
    private static function stripComments(string $source): string
    {
        $stripped = \preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;

        return \preg_replace('#//[^\n]*#', '', $stripped) ?? $stripped;
    }
}
