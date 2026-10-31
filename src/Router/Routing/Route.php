<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Routing;

use Polidog\Relayer\Http\RequestPath;

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
     * @param string $path percent-encoded path
     *
     * @return null|array<string, string> Decoded parameters if matched, null otherwise
     */
    public function match(string $path): ?array
    {
        $matchable = RequestPath::forMatching($path);

        return null === $matchable ? null : $this->matchDecoded($matchable);
    }

    /**
     * {@see match()} for a path already run through
     * {@see RequestPath::forMatching()}, so a collection decodes once rather
     * than once per route.
     *
     * @return null|array<string, string>
     */
    public function matchDecoded(string $matchable): ?array
    {
        if (\preg_match($this->regex, $matchable, $matches)) {
            $params = [];
            foreach ($this->paramNames as $name) {
                if (isset($matches[$name])) {
                    $params[$name] = RequestPath::restoreCaptured($matches[$name]);
                }
            }

            return $params;
        }

        return null;
    }
}
