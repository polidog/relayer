<?php

declare(strict_types=1);

namespace Polidog\Relayer\Personalization;

use InvalidArgumentException;
use Polidog\UsePhp\Runtime\Element;

/**
 * Authoring-side helper for declaring a client-hydrated fragment inside a
 * page. Emits an Element whose SSR HTML contains the fallback content (the
 * shape served to anonymous / pre-hydration viewers) plus two data attributes
 * the client hydrator (`public/relayer-personalize.js`) reads to fetch the
 * personalized HTML from `/_relayer/personalize/{id}` after page load.
 *
 * The corresponding server handler lives at `src/Personalize/{id}.psx` —
 * a flat 1:1 mapping by id, matching the framework's file-based-router
 * convention.
 */
final class Fragment
{
    public const PATH_PREFIX = '/_relayer/personalize/';

    /**
     * Ids appear in URLs and as filesystem names. Requiring the first
     * character to be alphanumeric blocks path-traversal shapes like `..`
     * or `.foo` before they reach the filesystem resolver. Hyphens,
     * underscores, and inner dots are allowed so `user-header`,
     * `cart_summary`, and `a.b.c` all work.
     */
    private const ID_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/';

    public static function placeholder(
        string $id,
        Element|string|null $fallback = null,
        string $tag = 'div',
    ): Element {
        self::assertIdShape($id);

        $children = null === $fallback ? [] : [$fallback];

        return new Element($tag, [
            'data-relayer-personalize' => $id,
            'data-relayer-endpoint'    => self::PATH_PREFIX . $id,
        ], $children);
    }

    public static function isValidId(string $id): bool
    {
        return 1 === \preg_match(self::ID_PATTERN, $id);
    }

    private static function assertIdShape(string $id): void
    {
        if (!self::isValidId($id)) {
            throw new InvalidArgumentException(
                "Invalid personalize id \"{$id}\": expected [a-zA-Z0-9._-]+",
            );
        }
    }
}
