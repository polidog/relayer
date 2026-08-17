<?php

declare(strict_types=1);

namespace Polidog\Relayer\Scaffold;

use Closure;
use Polidog\Relayer\Router\Api\RouteHandlers;
use Polidog\Relayer\Router\Routing\PageScanner;
use Polidog\Relayer\Router\Routing\Route;
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

        [$routes, $warnings] = self::describeRoutes($root, $collection);

        if ([] === $routes) {
            $write('No routes found under src/Pages.');

            return 0;
        }

        if (\in_array('--json', $args, true)) {
            $json = \json_encode($routes, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            $write(false === $json ? '[]' : $json);
        } elseif (\in_array('--graph', $args, true) || \in_array('--mermaid', $args, true)) {
            foreach (self::mermaid($routes) as $line) {
                $write($line);
            }
        } else {
            // [methods, path, type, file] rows, sorted by path for readability.
            $rows = \array_map(
                static fn (array $route): array => [$route['methods'], $route['path'], $route['type'], $route['file']],
                $routes,
            );

            \array_unshift($rows, ['METHODS', 'PATH', 'TYPE', 'FILE']);
            foreach (self::format($rows) as $line) {
                $write($line);
            }
        }

        if ([] !== $warnings) {
            $write('');
            foreach ($warnings as $warning) {
                $write($warning);
            }
        }

        return 0;
    }

    /**
     * @param iterable<Route> $collection
     *
     * @return array{list<array{methods: string, path: string, type: string, file: string, params: list<string>}>, list<string>}
     */
    private static function describeRoutes(string $root, iterable $collection): array
    {
        $routes = [];
        $warnings = [];

        foreach ($collection as $route) {
            $file = self::relative($root, $route->pagePath);

            if ($route->isApi) {
                try {
                    $methods = \implode(',', RouteHandlers::fromFile($route->pagePath)->allowedMethods());
                } catch (Throwable $e) {
                    // Don't abort the whole listing for one bad file, but
                    // don't hide it either — a misconfigured route.php is
                    // exactly what `relayer routes` should surface.
                    $methods = '?';
                    $warnings[] = 'warning: ' . $file . ': ' . $e->getMessage();
                }
            } else {
                // Pages dispatch GET (render) and POST (server actions /
                // useState), so both reach a page.
                $methods = 'GET,POST';
            }

            $routes[] = [
                'methods' => $methods,
                'path' => $route->pattern,
                'type' => $route->isApi ? 'api' : 'page',
                'file' => $file,
                'params' => \array_values($route->paramNames),
            ];
        }

        \usort($routes, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);

        return [$routes, $warnings];
    }

    /**
     * @param list<array{methods: string, path: string, type: string, file: string, params: list<string>}> $routes
     *
     * @return list<string>
     */
    private static function mermaid(array $routes): array
    {
        $lines = ['flowchart TD'];
        $nodeIds = ['/' => 'root'];
        $labels = ['/' => '/'];
        $edges = [];

        foreach ($routes as $route) {
            $parts = '/' === $route['path'] ? [] : \explode('/', \trim($route['path'], '/'));
            $current = '/';

            foreach ($parts as $part) {
                $next = '/' === $current ? '/' . $part : $current . '/' . $part;
                $nodeIds[$next] ??= 'n' . \count($nodeIds);
                $labels[$next] ??= $next;
                $edges[$current . '->' . $next] = [$current, $next];
                $current = $next;
            }

            $labels[$route['path']] = \sprintf(
                '%s<br/>%s %s<br/>%s',
                $route['path'],
                $route['methods'],
                $route['type'],
                $route['file'],
            );
        }

        foreach ($nodeIds as $path => $id) {
            $lines[] = '  ' . $id . '["' . self::escapeMermaidLabel($labels[$path]) . '"]';
        }

        foreach ($edges as [$from, $to]) {
            $lines[] = '  ' . $nodeIds[$from] . ' --> ' . $nodeIds[$to];
        }

        return $lines;
    }

    private static function escapeMermaidLabel(string $label): string
    {
        return \str_replace(['\\', '"'], ['\\\\', '\"'], $label);
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
