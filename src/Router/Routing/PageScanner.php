<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Routing;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class PageScanner
{
    private const PAGE_FILES = ['page.psx', 'page.php'];
    // API handlers return data, never a JSX Element, so there is no `.psx`
    // form — `route.php` only. A directory holds a page OR a route, never
    // both (same rule the Next.js App Router enforces for page/route).
    private const ROUTE_FILE = 'route.php';
    private const LAYOUT_FILES = ['layout.psx', 'layout.php'];
    private const ERROR_FILES = ['error.psx', 'error.php'];
    private const DYNAMIC_SEGMENT_PATTERN = '/^\[([a-zA-Z_][a-zA-Z0-9_]*)\]$/';

    public function __construct(
        private readonly string $appDirectory,
    ) {}

    public function scan(): RouteCollection
    {
        $collection = new RouteCollection();
        $appDir = \rtrim($this->appDirectory, '/');

        if (!\is_dir($appDir)) {
            throw new RuntimeException("App directory does not exist: {$appDir}");
        }

        foreach ($this->findRoutables($appDir) as $routable) {
            $collection->add($this->createRoute($appDir, $routable['path'], $routable['isApi']));
        }

        return $collection;
    }

    public function getErrorPagePath(): ?string
    {
        $appDir = \rtrim($this->appDirectory, '/');
        $found = [];

        foreach (self::ERROR_FILES as $name) {
            $candidate = $appDir . '/' . $name;
            if (\file_exists($candidate)) {
                $found[] = $candidate;
            }
        }

        if (\count($found) > 1) {
            throw new RuntimeException(
                "Both error.psx and error.php exist in {$appDir}. "
                . 'Remove one — having both makes error page resolution ambiguous.',
            );
        }

        return $found[0] ?? null;
    }

    /**
     * Discover the routable file in each directory under $appDir. A
     * directory contributes at most one route, backed by either a page
     * file (`page.psx` / `page.php`) or an API handler (`route.php`).
     *
     * Two ambiguities are rejected with an actionable error rather than
     * picking one silently — both almost always mean a leftover file:
     *  - `page.psx` AND `page.php` in the same directory;
     *  - a page file AND `route.php` in the same directory (a segment is
     *    either a rendered page or a JSON endpoint, not both).
     *
     * @return list<array{path: string, isApi: bool}>
     */
    private function findRoutables(string $appDir): array
    {
        // Per directory: collected page-file candidates + the route.php path.
        /** @var array<string, array{pages: array<string, string>, route: null|string}> $perDir */
        $perDir = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            $dir = $file->getPath();
            $perDir[$dir] ??= ['pages' => [], 'route' => null];

            if (\in_array($name, self::PAGE_FILES, true)) {
                $perDir[$dir]['pages'][$name] = $file->getPathname();
            } elseif (self::ROUTE_FILE === $name) {
                $perDir[$dir]['route'] = $file->getPathname();
            }
        }

        $routables = [];
        foreach ($perDir as $dir => $found) {
            $pages = $found['pages'];
            $route = $found['route'];

            if (\count($pages) > 1) {
                throw new RuntimeException(
                    "Both page.psx and page.php exist in {$dir}. "
                    . 'Remove one — having both makes routing ambiguous.',
                );
            }

            if ([] !== $pages && null !== $route) {
                throw new RuntimeException(
                    "Both a page file and route.php exist in {$dir}. "
                    . 'A directory maps to a rendered page OR a JSON API route, not both.',
                );
            }

            if ([] !== $pages) {
                $routables[] = ['path' => \reset($pages), 'isApi' => false];
            } elseif (null !== $route) {
                $routables[] = ['path' => $route, 'isApi' => true];
            }
        }

        return $routables;
    }

    private function createRoute(string $appDir, string $filePath, bool $isApi): Route
    {
        $routeDir = \dirname($filePath);
        $relativePath = $this->getRelativePath($appDir, $routeDir);

        $pattern = $this->buildPattern($relativePath);
        [$regex, $paramNames] = $this->buildRegex($pattern);
        // API routes render no HTML, so layouts never apply to them.
        $layoutPaths = $isApi ? [] : $this->findLayouts($appDir, $routeDir);

        $segments = '/' === $pattern ? [] : \explode('/', \trim($pattern, '/'));
        $totalSegments = \count($segments);
        $staticSegments = 0;

        foreach ($segments as $segment) {
            if (!\preg_match(self::DYNAMIC_SEGMENT_PATTERN, $segment)) {
                ++$staticSegments;
            }
        }

        return new Route(
            pattern: $pattern,
            regex: $regex,
            pagePath: $filePath,
            layoutPaths: $layoutPaths,
            paramNames: $paramNames,
            staticSegments: $staticSegments,
            totalSegments: $totalSegments,
            isApi: $isApi,
        );
    }

    private function getRelativePath(string $from, string $to): string
    {
        $from = \rtrim($from, '/');
        $to = \rtrim($to, '/');

        if ($from === $to) {
            return '';
        }

        if (!\str_starts_with($to, $from . '/')) {
            throw new RuntimeException("Path {$to} is not under {$from}");
        }

        return \substr($to, \strlen($from) + 1);
    }

    private function buildPattern(string $relativePath): string
    {
        if ('' === $relativePath) {
            return '/';
        }

        $segments = \explode('/', $relativePath);
        $patternSegments = [];

        foreach ($segments as $segment) {
            if (\preg_match(self::DYNAMIC_SEGMENT_PATTERN, $segment, $matches)) {
                $patternSegments[] = '[' . $matches[1] . ']';
            } else {
                $patternSegments[] = $segment;
            }
        }

        return '/' . \implode('/', $patternSegments);
    }

    /**
     * @return array{0: string, 1: array<string>}
     */
    private function buildRegex(string $pattern): array
    {
        $paramNames = [];

        if ('/' === $pattern) {
            return ['#^/$#', $paramNames];
        }

        $segments = \explode('/', \trim($pattern, '/'));
        $regexParts = [];

        foreach ($segments as $segment) {
            if (\preg_match(self::DYNAMIC_SEGMENT_PATTERN, $segment, $matches)) {
                $paramName = $matches[1];
                $paramNames[] = $paramName;
                $regexParts[] = '(?P<' . $paramName . '>[^/]+)';
            } else {
                $regexParts[] = \preg_quote($segment, '#');
            }
        }

        $regex = '#^/' . \implode('/', $regexParts) . '$#';

        return [$regex, $paramNames];
    }

    /**
     * @return array<string>
     */
    private function findLayouts(string $appDir, string $pageDir): array
    {
        $layouts = [];

        $rootLayout = $this->resolveLayoutInDir($appDir);
        if (null !== $rootLayout) {
            $layouts[] = $rootLayout;
        }

        $relativePath = $this->getRelativePath($appDir, $pageDir);

        if ('' !== $relativePath) {
            $segments = \explode('/', $relativePath);
            $path = $appDir;

            foreach ($segments as $segment) {
                $path .= '/' . $segment;
                $layoutPath = $this->resolveLayoutInDir($path);

                if (null !== $layoutPath) {
                    $layouts[] = $layoutPath;
                }
            }
        }

        return $layouts;
    }

    /**
     * Return the layout file in $dir, preferring .psx over .php.
     * Errors when both extensions coexist — same rule as page.psx/page.php.
     */
    private function resolveLayoutInDir(string $dir): ?string
    {
        $found = [];
        foreach (self::LAYOUT_FILES as $name) {
            $candidate = $dir . '/' . $name;
            if (\file_exists($candidate)) {
                $found[] = $candidate;
            }
        }

        if (\count($found) > 1) {
            throw new RuntimeException(
                "Both layout.psx and layout.php exist in {$dir}. "
                . 'Remove one — having both makes layout resolution ambiguous.',
            );
        }

        return $found[0] ?? null;
    }
}
