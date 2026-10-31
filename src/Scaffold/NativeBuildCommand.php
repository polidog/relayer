<?php

declare(strict_types=1);

namespace Polidog\Relayer\Scaffold;

use Closure;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\Native\GoEntrypoint;
use Polidog\Relayer\Router\Native\GoRouteFile;
use Polidog\Relayer\Router\Native\GoToolchain;
use Polidog\Relayer\Router\Native\NativeSlug;
use Polidog\Relayer\Router\Routing\PageScanner;
use Polidog\Relayer\Router\Routing\Route;
use Polidog\Relayer\Router\Routing\RouteKind;
use RuntimeException;

/**
 * `relayer native:build` — compile every `route.go` under `src/Pages` into
 * the binary the router starts per request.
 *
 * A `route.go` has no `main()` (handlers only, like the Next.js
 * prototype), so for each one this:
 *
 *   1. reads the declared handler methods ({@see GoRouteFile}; none is an
 *      error — the file would answer nothing),
 *   2. lays out `var/cache/native/build/<slug>/` with a copy of the
 *      `route.go`, the generated `relayer_main.go` ({@see GoEntrypoint})
 *      and a `go.mod` (the user's own `go.mod` / `go.sum` beside the
 *      `route.go` are copied instead when present),
 *   3. runs `go build -o var/cache/native/bin/<slug>` there.
 *
 * `<slug>` is {@see NativeSlug::forPattern()} — the same name the
 * dispatcher looks up, so build and runtime cannot disagree.
 *
 * Needs a Go toolchain (`go` on PATH, or `GO_BIN=/path/to/go`), which is
 * why it is a separate command and *not* part of `relayer compile`: a
 * deploy without any `route.go` must keep working on a PHP-only image.
 * Same testable shape as the other commands (injected writer + cwd).
 */
final class NativeBuildCommand
{
    /**
     * @param list<string>               $args  argv after the `native:build` verb (unused; reserved)
     * @param null|Closure(string): void $write line writer; defaults to STDOUT
     * @param null|string                $cwd   project root; defaults to getcwd()
     *
     * @return int 0 success (incl. nothing to build), 1 on a missing toolchain,
     *             an invalid route.go, or a failed `go build`
     */
    public static function run(array $args, ?Closure $write = null, ?string $cwd = null): int
    {
        $write ??= static function (string $line): void {
            \fwrite(\STDOUT, $line . "\n");
        };

        $root = \rtrim('' !== (string) $cwd ? (string) $cwd : (\getcwd() ?: '.'), '/');
        $appDir = $root . '/src/Pages';

        if (!\is_dir($appDir)) {
            $write('No src/Pages directory found in the current project.');
            $write('Run `relayer native:build` from a Relayer project root.');

            return 1;
        }

        try {
            $collection = (new PageScanner($appDir))->scan();
        } catch (RuntimeException $e) {
            $write('Could not scan routes: ' . $e->getMessage());

            return 1;
        }

        $natives = [];
        foreach ($collection as $route) {
            if (RouteKind::Native === $route->kind) {
                $natives[] = $route;
            }
        }

        if ([] === $natives) {
            $write(\sprintf('No native routes (%s) found under src/Pages.', GoRouteFile::NAME));

            return 0;
        }

        \usort($natives, static fn (Route $a, Route $b): int => $a->pattern <=> $b->pattern);

        // Authoring errors first — they need no toolchain, and a missing
        // handler is a more useful first complaint than a missing `go`.
        /** @var list<array{Route, string, list<string>}> $jobs route, source, declared methods */
        $jobs = [];
        foreach ($natives as $route) {
            $relativeSource = self::relative($root, $route->pagePath);

            $source = @\file_get_contents($route->pagePath);
            if (!\is_string($source)) {
                $write(\sprintf('Could not read %s.', $relativeSource));

                return 1;
            }

            if (!GoRouteFile::isMainPackage($source)) {
                $write(\sprintf(
                    '%s must declare `package main` (the generated entrypoint lives in the same package).',
                    $relativeSource,
                ));

                return 1;
            }

            $declared = GoRouteFile::declaredMethods($source);
            if ([] === $declared) {
                $write(\sprintf(
                    '%s declares no handler. Export at least one of %s, e.g. '
                    . '`func GET(w http.ResponseWriter, r *http.Request)`.',
                    $relativeSource,
                    \implode(' / ', GoRouteFile::HANDLER_METHODS),
                ));

                return 1;
            }

            $jobs[] = [$route, $source, $declared];
        }

        $go = GoToolchain::locate();
        if (null === $go) {
            $write(GoToolchain::NOT_FOUND_MESSAGE);

            return 1;
        }

        $binDir = $root . '/' . Relayer::NATIVE_BIN_DIR;
        $buildDir = $root . '/' . Relayer::NATIVE_BUILD_DIR;

        foreach ($jobs as [$route, $source, $declared]) {
            if (0 !== ($status = self::buildRoute($route, $source, $declared, $go, $root, $binDir, $buildDir, $write))) {
                return $status;
            }
        }

        return 0;
    }

    /**
     * @param list<string>          $declared
     * @param Closure(string): void $write
     */
    private static function buildRoute(
        Route $route,
        string $source,
        array $declared,
        string $go,
        string $root,
        string $binDir,
        string $buildDir,
        Closure $write,
    ): int {
        $relativeSource = self::relative($root, $route->pagePath);
        $slug = NativeSlug::forPattern($route->pattern);
        $workDir = $buildDir . '/' . $slug;
        $binary = $binDir . '/' . $slug;

        foreach ([$workDir, $binDir] as $dir) {
            if (!\is_dir($dir) && !@\mkdir($dir, 0o775, true) && !\is_dir($dir)) {
                $write(\sprintf('Could not create directory "%s".', self::relative($root, $dir)));

                return 1;
            }
        }

        // Start from a clean build dir so a file removed from the user's
        // directory (a go.mod they deleted) does not linger.
        foreach (\glob($workDir . '/*') ?: [] as $stale) {
            if (\is_file($stale)) {
                @\unlink($stale);
            }
        }

        $routeDir = \dirname($route->pagePath);
        $files = [
            GoRouteFile::NAME => $source,
            GoEntrypoint::FILE_NAME => GoEntrypoint::render($declared),
        ];

        // Respect a user-provided module (third-party deps, a pinned `go`
        // directive); otherwise generate a stdlib-only one.
        $userGoMod = @\file_get_contents($routeDir . '/go.mod');
        $files['go.mod'] = \is_string($userGoMod) ? $userGoMod : GoEntrypoint::renderGoMod(NativeSlug::moduleName($slug));
        $userGoSum = @\file_get_contents($routeDir . '/go.sum');
        if (\is_string($userGoSum)) {
            $files['go.sum'] = $userGoSum;
        }

        foreach ($files as $name => $contents) {
            if (false === @\file_put_contents($workDir . '/' . $name, $contents)) {
                $write(\sprintf('Could not write %s.', self::relative($root, $workDir . '/' . $name)));

                return 1;
            }
        }

        [$exit, $output] = GoToolchain::build($go, $workDir, $binary);

        if (0 !== $exit) {
            $write(\sprintf('failed  %s  (%s)', $route->pattern, $relativeSource));
            foreach (\explode("\n", $output) as $line) {
                if ('' !== $line) {
                    $write('  ' . $line);
                }
            }

            return 1;
        }

        $write(\sprintf('built  %s  -> %s', $route->pattern, self::relative($root, $binary)));

        return 0;
    }

    private static function relative(string $root, string $path): string
    {
        $prefix = $root . '/';

        return \str_starts_with($path, $prefix) ? \substr($path, \strlen($prefix)) : $path;
    }
}
