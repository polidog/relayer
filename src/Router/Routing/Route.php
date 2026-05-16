<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Routing;

final class Route
{
    /**
     * @param string        $pattern        URL pattern (e.g., '/blog/[slug]')
     * @param string        $regex          Compiled regex pattern
     * @param string        $pagePath       Absolute path to page.php
     * @param array<string> $layoutPaths    Absolute paths to layout files (from root to deepest)
     * @param array<string> $paramNames     Parameter names from dynamic segments
     * @param int           $staticSegments Number of static segments (for sorting priority)
     * @param int           $totalSegments  Total number of segments
     * @param bool          $isApi          true when backed by `route.php` (a JSON
     *                                      API handler) instead of a page — API
     *                                      routes carry no layouts and skip the
     *                                      HTML/Document render pipeline
     */
    public function __construct(
        public readonly string $pattern,
        public readonly string $regex,
        public readonly string $pagePath,
        public readonly array $layoutPaths,
        public readonly array $paramNames,
        public readonly int $staticSegments,
        public readonly int $totalSegments,
        public readonly bool $isApi = false,
    ) {}

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
