<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

/**
 * Compute the route-derived page id used as a stable namespace for action
 * tokens and component state keys.
 *
 * The id is the page's path relative to the application root with the
 * `/page.psx` / `/route.php` (and `.psx.php` cached) suffix stripped — for
 * `src/Pages/blog/[slug]/page.psx` under `appDirectory = src/Pages`, the id
 * is `/blog/[slug]`. The original source path must be used (NOT the opaque
 * compiled cache filename) so the id stays meaningful across deploys.
 */
final class PageIdentifier
{
    public function __construct(
        private readonly string $appDirectory,
    ) {}

    public function pageId(string $pagePath): string
    {
        $relative = \str_replace($this->appDirectory, '', $pagePath);
        $relative = (string) \preg_replace('#/(?:page|route)\.(psx\.php|psx|php)$#', '', $relative);

        if ('' === $relative || '/' === $relative) {
            return '/';
        }

        return $relative;
    }
}
