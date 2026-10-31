<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Routing;

final class Route
{
    /**
     * `true` when backed by `route.php` — kept as a read-only, backward
     * compatible view of {@see $kind} (a `Native` route is *not* an API
     * route in this sense: it has no PHP handler map).
     */
    public readonly bool $isApi;

    /** Which dispatch pipeline a match goes down. */
    public readonly RouteKind $kind;

    /**
     * @param string         $pattern        URL pattern (e.g., '/blog/[slug]')
     * @param string         $regex          Compiled regex pattern
     * @param string         $pagePath       Absolute path to the handler file
     *                                       (`page.*`, `route.php` or `route.go`)
     * @param array<string>  $layoutPaths    Absolute paths to layout files (from root to deepest)
     * @param array<string>  $paramNames     Parameter names from dynamic segments
     * @param int            $staticSegments Number of static segments (for sorting priority)
     * @param int            $totalSegments  Total number of segments
     * @param bool           $isApi          legacy flag: true when backed by `route.php`.
     *                                       Ignored when `$kind` is given; kept in this
     *                                       position so existing positional callers
     *                                       keep working
     * @param null|RouteKind $kind           the handler kind; defaults to what `$isApi`
     *                                       implies (`Api` / `Page`)
     */
    public function __construct(
        public readonly string $pattern,
        public readonly string $regex,
        public readonly string $pagePath,
        public readonly array $layoutPaths,
        public readonly array $paramNames,
        public readonly int $staticSegments,
        public readonly int $totalSegments,
        bool $isApi = false,
        ?RouteKind $kind = null,
    ) {
        $this->kind = $kind ?? RouteKind::fromLegacyFlag($isApi);
        $this->isApi = RouteKind::Api === $this->kind;
    }

    public function isDynamic(): bool
    {
        return \count($this->paramNames) > 0;
    }

    /**
     * @return null|array<string, string> Parameters if matched, null otherwise
     */
    public function match(string $path): ?array
    {
        if (\preg_match($this->regex, $path, $matches)) {
            $params = [];
            foreach ($this->paramNames as $name) {
                if (isset($matches[$name])) {
                    $params[$name] = $matches[$name];
                }
            }

            return $params;
        }

        return null;
    }
}
