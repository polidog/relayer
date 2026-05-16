<?php

declare(strict_types=1);

namespace Polidog\Relayer\Scaffold;

use Closure;
use Polidog\Relayer\Router\Api\RouteHandlers;
use Polidog\Relayer\Router\Routing\PageScanner;
use RuntimeException;
use Throwable;

/**
 * `relayer routes` — list the routes Relayer discovers under `src/Pages`.
 *
 * A read-only introspection aid: it reuses {@see PageScanner} (the exact
 * discovery the router uses) so what it prints is what the app will serve.
 * Same testable shape as {@see InitCommand} — injected line writer and cwd,
 * no STDOUT/chdir coupling.
 *
 * For API routes (`route.php`) the declared HTTP methods are listed; this
 * `require`s the file (the route contract is declaration-free, so that is
 * safe) and degrades to `?` if it cannot be loaded, rather than aborting
 * the whole listing.
 */
final class RoutesCommand
{
    /**
     * @param list<string>               $args  argv after the `routes` verb (unused; reserved)
     * @param null|Closure(string): void $write line writer; defaults to STDOUT
     * @param null|string                $cwd   project root; defaults to getcwd()
     *
     * @return int 0 success, 1 when `src/Pages` is missing or unscannable
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
            $write('Run `relayer routes` from a Relayer project root.');

            return 1;
        }

        try {
            $collection = (new PageScanner($appDir))->scan();
        } catch (RuntimeException $e) {
            $write('Could not scan routes: ' . $e->getMessage());

            return 1;
        }

        // [methods, path, type, file] rows, sorted by path for readability.
        $rows = [];
        foreach ($collection as $route) {
            $rows[] = [
                $route->isApi ? self::apiMethods($route->pagePath) : 'GET',
                $route->pattern,
                $route->isApi ? 'api' : 'page',
                self::relative($root, $route->pagePath),
            ];
        }

        if ([] === $rows) {
            $write('No routes found under src/Pages.');

            return 0;
        }

        \usort($rows, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        \array_unshift($rows, ['METHODS', 'PATH', 'TYPE', 'FILE']);
        foreach (self::format($rows) as $line) {
            $write($line);
        }

        return 0;
    }

    private static function apiMethods(string $routeFile): string
    {
        try {
            return \implode(',', RouteHandlers::fromFile($routeFile)->allowedMethods());
        } catch (Throwable) {
            return '?';
        }
    }

    private static function relative(string $root, string $path): string
    {
        $prefix = $root . '/';

        return \str_starts_with($path, $prefix) ? \substr($path, \strlen($prefix)) : $path;
    }

    /**
     * Left-pad each column to its widest cell so the table aligns.
     *
     * @param list<array{string, string, string, string}> $rows
     *
     * @return list<string>
     */
    private static function format(array $rows): array
    {
        $widths = [0, 0, 0, 0];
        foreach ($rows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = \max($widths[$i], \strlen($cell));
            }
        }

        $lines = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $i => $cell) {
                $cells[] = \str_pad($cell, $widths[$i]);
            }
            $lines[] = \rtrim(\implode('  ', $cells));
        }

        return $lines;
    }
}
