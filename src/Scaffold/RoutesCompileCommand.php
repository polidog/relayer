<?php

declare(strict_types=1);

namespace Polidog\Relayer\Scaffold;

use Closure;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\Routing\CompiledRoutes;
use Polidog\Relayer\Router\Routing\PageScanner;
use RuntimeException;

/**
 * `relayer routes:compile` — write the precompiled route artifact.
 *
 * The production counterpart to `vendor/bin/usephp compile src/Pages`:
 * run it once at deploy and the router reads
 * `var/cache/routes/routes.php` instead of walking `src/Pages/` on every
 * request. Reuses {@see PageScanner} (the exact discovery the router
 * uses), so the snapshot is what the app will serve — and scan-time
 * ambiguities (page/route or route-group URL collisions) fail the
 * compile here, at deploy, rather than on the first production request.
 *
 * Same testable shape as {@see RoutesCommand}: injected line writer and
 * cwd, no STDOUT/chdir coupling.
 */
final class RoutesCompileCommand
{
    /**
     * @param list<string>               $args  argv after the `routes:compile` verb (unused; reserved)
     * @param null|Closure(string): void $write line writer; defaults to STDOUT
     * @param null|string                $cwd   project root; defaults to getcwd()
     *
     * @return int 0 success, 1 on a missing/unscannable src/Pages or a write failure
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
            $write('Run `relayer routes:compile` from a Relayer project root.');

            return 1;
        }

        try {
            $collection = (new PageScanner($appDir))->scan();
        } catch (RuntimeException $e) {
            $write('Could not compile routes: ' . $e->getMessage());

            return 1;
        }

        $outFile = $root . '/' . Relayer::COMPILED_ROUTES_FILE;

        if (!CompiledRoutes::write($collection, $appDir, $outFile)) {
            $write("Could not write {$outFile}.");

            return 1;
        }

        $count = \count($collection);
        $write(\sprintf(
            'Compiled %d route%s to %s',
            $count,
            1 === $count ? '' : 's',
            Relayer::COMPILED_ROUTES_FILE,
        ));

        return 0;
    }
}
