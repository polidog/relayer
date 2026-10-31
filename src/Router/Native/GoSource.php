<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Native;

/**
 * The little the framework needs to know about a Go source file without a
 * toolchain, shared by {@see GoRouteFile} and {@see GoMiddlewareFile}.
 */
final class GoSource
{
    private const PACKAGE_MAIN_PATTERN = '/^package\s+main\b/m';

    public static function isMainPackage(string $source): bool
    {
        return 1 === \preg_match(self::PACKAGE_MAIN_PATTERN, self::stripComments($source));
    }

    /**
     * Drop line and block comments so a commented-out declaration is not
     * mistaken for a real one. Deliberately does not parse string
     * literals: a `"func GET("` inside a string is contrived enough not
     * to pay a tokenizer for.
     */
    public static function stripComments(string $source): string
    {
        $stripped = \preg_replace('#/\*.*?\*/#s', '', $source) ?? $source;

        return \preg_replace('#//[^\n]*#', '', $stripped) ?? $stripped;
    }
}
