<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Routing;

use Polidog\Relayer\Router\Native\GoRouteFile;
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
    // A handler in another language (Go today). Built ahead of time by
    // `relayer native:build` and run as a CGI process; discovered here so
    // it takes part in matching, sorting and `relayer routes` exactly like
    // the PHP kinds. Same one-handler-per-directory rule.
    private const NATIVE_ROUTE_FILE = GoRouteFile::NAME;
    private const LAYOUT_FILES = ['layout.psx', 'layout.php'];
    private const ERROR_FILES = ['error.psx', 'error.php'];
    private const DYNAMIC_SEGMENT_PATTERN = '/^\[([a-zA-Z_][a-zA-Z0-9_]*)\]$/';
    // Next.js App Router conventions for directory names:
    //  - `(group)`  organises files without contributing a URL segment;
    //  - `_private` opts the folder and everything under it out of routing.
    private const ROUTE_GROUP_PATTERN = '/^\(.+\)$/';
    private const PRIVATE_SEGMENT_PREFIX = '_';

    public function __construct(
        private readonly string $appDirectory,
    ) {}

    /**
     * The normalised app directory this scanner walks — the single source
     * of truth for turning the compiled artifact's relative paths back
     * into absolute ones (see {@see CompiledRoutes::load()}).
     */
    public function appDirectory(): string
    {
        return \rtrim($this->appDirectory, '/');
    }

    public function scan(): RouteCollection
    {
        $collection = new RouteCollection();
        $appDir = \rtrim($this->appDirectory, '/');

        if (!\is_dir($appDir)) {
            throw new RuntimeException("App directory does not exist: {$appDir}");
        }

        // Route groups let two different paths collapse onto one URL
        // (e.g. `(a)/about/` and `(b)/about/` both → `/about`). Next.js
        // rejects that; we do too, naming both files instead of silently
        // letting sort order pick a winner.
        /** @var array<string, string> $patternSource pattern → source file */
        $patternSource = [];

        foreach ($this->findRoutables($appDir) as $routable) {
            $route = $this->createRoute($appDir, $routable['path'], $routable['kind']);

            if (isset($patternSource[$route->pattern])) {
                throw new RuntimeException(\sprintf(
                    "Route pattern \"%s\" is produced by two files:\n  %s\n  %s\n"
                    . 'Route groups (parenthesised folders) and the remaining '
                    . 'path must still yield a unique URL.',
                    $route->pattern,
                    $patternSource[$route->pattern],
                    $routable['path'],
                ));
            }

            $patternSource[$route->pattern] = $routable['path'];
            $collection->add($route);
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
     * directory contributes at most one route, backed by a page file
     * (`page.psx` / `page.php`), an API handler (`route.php`) or a native
     * handler (`route.go`).
     *
     * Ambiguities are rejected with an actionable error rather than
     * picking one silently — they almost always mean a leftover file:
     *  - `page.psx` AND `page.php` in the same directory;
     *  - a page file AND a `route.*` in the same directory (a segment is
     *    either a rendered page or an endpoint, not both);
     *  - `route.php` AND `route.go` in the same directory (one handler,
     *    one language).
     *
     * @return list<array{path: string, kind: RouteKind}>
     */
    private function findRoutables(string $appDir): array
    {
        // Per directory: collected page-file candidates + the route.php /
        // route.go paths.
        /** @var array<string, array{pages: array<string, string>, route: null|string, native: null|string}> $perDir */
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

            // A `_private` folder anywhere on the path opts this file (and
            // its whole subtree) out of routing — it never becomes a route.
            if ($this->isPrivatePath($appDir, $dir)) {
                continue;
            }

            $perDir[$dir] ??= ['pages' => [], 'route' => null, 'native' => null];

            if (\in_array($name, self::PAGE_FILES, true)) {
                $perDir[$dir]['pages'][$name] = $file->getPathname();
            } elseif (self::ROUTE_FILE === $name) {
                $perDir[$dir]['route'] = $file->getPathname();
            } elseif (self::NATIVE_ROUTE_FILE === $name) {
                $perDir[$dir]['native'] = $file->getPathname();
            }
        }

        $routables = [];
        foreach ($perDir as $dir => $found) {
            $pages = $found['pages'];
            $route = $found['route'];
            $native = $found['native'];

            if (\count($pages) > 1) {
                throw new RuntimeException(
                    "Both page.psx and page.php exist in {$dir}. "
                    . 'Remove one — having both makes routing ambiguous.',
                );
            }

            if ([] !== $pages && (null !== $route || null !== $native)) {
                throw new RuntimeException(\sprintf(
                    'Both a page file and %s exist in %s. '
                    . 'A directory maps to a rendered page OR a JSON API route, not both.',
                    null !== $route ? self::ROUTE_FILE : self::NATIVE_ROUTE_FILE,
                    $dir,
                ));
            }

            if (null !== $route && null !== $native) {
                throw new RuntimeException(\sprintf(
                    'Both %s and %s exist in %s. '
                    . 'A directory maps to ONE handler — keep the PHP route or the native one, not both.',
                    self::ROUTE_FILE,
                    self::NATIVE_ROUTE_FILE,
                    $dir,
                ));
            }

            if ([] !== $pages) {
                $routables[] = ['path' => \reset($pages), 'kind' => RouteKind::Page];
            } elseif (null !== $route) {
                $routables[] = ['path' => $route, 'kind' => RouteKind::Api];
            } elseif (null !== $native) {
                $routables[] = ['path' => $native, 'kind' => RouteKind::Native];
            }
        }

        return $routables;
    }

    private function createRoute(string $appDir, string $filePath, RouteKind $kind): Route
    {
        $routeDir = \dirname($filePath);
        $relativePath = $this->getRelativePath($appDir, $routeDir);

        $pattern = $this->buildPattern($relativePath);
        [$regex, $paramNames] = $this->buildRegex($pattern);
        // Endpoints (API / native) render no HTML, so layouts never apply.
        $layoutPaths = $kind->isEndpoint() ? [] : $this->findLayouts($appDir, $routeDir);

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
            kind: $kind,
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

    /**
     * True when any directory between $appDir and $dir is a `_private`
     * folder. Such a folder — and everything nested under it — is an
     * implementation detail the router must never expose as a route.
     */
    private function isPrivatePath(string $appDir, string $dir): bool
    {
        $relativePath = $this->getRelativePath($appDir, $dir);

        if ('' === $relativePath) {
            return false;
        }

        foreach (\explode('/', $relativePath) as $segment) {
            if (\str_starts_with($segment, self::PRIVATE_SEGMENT_PREFIX)) {
                return true;
            }
        }

        return false;
    }

    private function buildPattern(string $relativePath): string
    {
        if ('' === $relativePath) {
            return '/';
        }

        $segments = \explode('/', $relativePath);
        $patternSegments = [];

        foreach ($segments as $segment) {
            if (1 === \preg_match(self::ROUTE_GROUP_PATTERN, $segment)) {
                // Organisational only — contributes no URL segment.
                continue;
            }

            if (\preg_match(self::DYNAMIC_SEGMENT_PATTERN, $segment, $matches)) {
                $patternSegments[] = '[' . $matches[1] . ']';
            } else {
                $patternSegments[] = $segment;
            }
        }

        // A path made entirely of route groups (e.g. `(marketing)/`) maps
        // to the root, exactly like an empty relative path.
        if ([] === $patternSegments) {
            return '/';
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
