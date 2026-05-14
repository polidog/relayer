<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Routing;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PageScanner
{
    private const PAGE_FILES = ['page.psx', 'page.php'];
    private const LAYOUT_FILES = ['layout.psx', 'layout.php'];
    private const ERROR_FILES = ['error.psx', 'error.php'];
    private const DYNAMIC_SEGMENT_PATTERN = '/^\[([a-zA-Z_][a-zA-Z0-9_]*)\]$/';

    public function __construct(
        private readonly string $appDirectory,
    ) {
    }

    public function scan(): RouteCollection
    {
        $collection = new RouteCollection();
        $appDir = rtrim($this->appDirectory, '/');

        if (!is_dir($appDir)) {
            throw new \RuntimeException("App directory does not exist: {$appDir}");
        }

        $pages = $this->findPages($appDir);

        foreach ($pages as $pagePath) {
            $route = $this->createRoute($appDir, $pagePath);
            if ($route !== null) {
                $collection->add($route);
            }
        }

        return $collection;
    }

    public function getErrorPagePath(): ?string
    {
        $appDir = rtrim($this->appDirectory, '/');
        $found = [];

        foreach (self::ERROR_FILES as $name) {
            $candidate = $appDir . '/' . $name;
            if (file_exists($candidate)) {
                $found[] = $candidate;
            }
        }

        if (\count($found) > 1) {
            throw new \RuntimeException(
                "Both error.psx and error.php exist in $appDir. "
                . 'Remove one — having both makes error page resolution ambiguous.'
            );
        }

        return $found[0] ?? null;
    }

    /**
     * @return array<string>
     */
    private function findPages(string $appDir): array
    {
        // Discover candidate page files per directory. If both page.psx and
        // page.php exist in the same directory we error: routing would be
        // ambiguous and one of the two is almost certainly a leftover.
        $perDir = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($appDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $name = $file->getFilename();
            if (!\in_array($name, self::PAGE_FILES, true)) {
                continue;
            }
            $perDir[$file->getPath()][$name] = $file->getPathname();
        }

        $pages = [];
        foreach ($perDir as $dir => $candidates) {
            if (\count($candidates) > 1) {
                throw new \RuntimeException(
                    "Both page.psx and page.php exist in $dir. "
                    . 'Remove one — having both makes routing ambiguous.'
                );
            }
            $pages[] = \reset($candidates);
        }

        return $pages;
    }

    private function createRoute(string $appDir, string $pagePath): ?Route
    {
        $pageDir = dirname($pagePath);
        $relativePath = $this->getRelativePath($appDir, $pageDir);

        $pattern = $this->buildPattern($relativePath);
        [$regex, $paramNames] = $this->buildRegex($pattern);
        $layoutPaths = $this->findLayouts($appDir, $pageDir);

        $segments = $pattern === '/' ? [] : explode('/', trim($pattern, '/'));
        $totalSegments = count($segments);
        $staticSegments = 0;

        foreach ($segments as $segment) {
            if (!preg_match(self::DYNAMIC_SEGMENT_PATTERN, $segment)) {
                $staticSegments++;
            }
        }

        return new Route(
            pattern: $pattern,
            regex: $regex,
            pagePath: $pagePath,
            layoutPaths: $layoutPaths,
            paramNames: $paramNames,
            staticSegments: $staticSegments,
            totalSegments: $totalSegments,
        );
    }

    private function getRelativePath(string $from, string $to): string
    {
        $from = rtrim($from, '/');
        $to = rtrim($to, '/');

        if ($from === $to) {
            return '';
        }

        if (!str_starts_with($to, $from . '/')) {
            throw new \RuntimeException("Path {$to} is not under {$from}");
        }

        return substr($to, strlen($from) + 1);
    }

    private function buildPattern(string $relativePath): string
    {
        if ($relativePath === '') {
            return '/';
        }

        $segments = explode('/', $relativePath);
        $patternSegments = [];

        foreach ($segments as $segment) {
            if (preg_match(self::DYNAMIC_SEGMENT_PATTERN, $segment, $matches)) {
                $patternSegments[] = '[' . $matches[1] . ']';
            } else {
                $patternSegments[] = $segment;
            }
        }

        return '/' . implode('/', $patternSegments);
    }

    /**
     * @return array{0: string, 1: array<string>}
     */
    private function buildRegex(string $pattern): array
    {
        $paramNames = [];

        if ($pattern === '/') {
            return ['#^/$#', $paramNames];
        }

        $segments = explode('/', trim($pattern, '/'));
        $regexParts = [];

        foreach ($segments as $segment) {
            if (preg_match(self::DYNAMIC_SEGMENT_PATTERN, $segment, $matches)) {
                $paramName = $matches[1];
                $paramNames[] = $paramName;
                $regexParts[] = '(?P<' . $paramName . '>[^/]+)';
            } else {
                $regexParts[] = preg_quote($segment, '#');
            }
        }

        $regex = '#^/' . implode('/', $regexParts) . '$#';

        return [$regex, $paramNames];
    }

    /**
     * @return array<string>
     */
    private function findLayouts(string $appDir, string $pageDir): array
    {
        $layouts = [];

        $rootLayout = $this->resolveLayoutInDir($appDir);
        if ($rootLayout !== null) {
            $layouts[] = $rootLayout;
        }

        $relativePath = $this->getRelativePath($appDir, $pageDir);

        if ($relativePath !== '') {
            $segments = explode('/', $relativePath);
            $path = $appDir;

            foreach ($segments as $segment) {
                $path .= '/' . $segment;
                $layoutPath = $this->resolveLayoutInDir($path);

                if ($layoutPath !== null) {
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
            if (file_exists($candidate)) {
                $found[] = $candidate;
            }
        }

        if (\count($found) > 1) {
            throw new \RuntimeException(
                "Both layout.psx and layout.php exist in $dir. "
                . 'Remove one — having both makes layout resolution ambiguous.'
            );
        }

        return $found[0] ?? null;
    }
}
