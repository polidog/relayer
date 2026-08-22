<?php

declare(strict_types=1);

namespace Polidog\Relayer\Scaffold;

use Closure;
use Polidog\Relayer\Relayer;
use Polidog\UsePhp\Psx\CompileCommand as PsxCompileCommand;
use Throwable;

/**
 * `relayer compile` — build every deploy-time artifact in one go.
 *
 * Runs the three precompile steps the deploy checklist lists, in the
 * order they depend on each other, and stops at the first failure:
 *
 *   1. `.psx` -> PHP (delegates to use-php's `usephp compile`, over
 *      `src/Pages` and `src/Components`, into `var/cache/psx`),
 *   2. {@see RoutesCompileCommand} (`src/Pages/` -> route map),
 *   3. {@see ContainerCompileCommand} (DI container -> PHP class).
 *
 * Each step is the same code path as its standalone command, so the
 * artifacts are byte-identical; this is purely a convenience so a
 * deploy script needs one command instead of three across two binaries.
 * Same testable shape as the others (injected line writer + cwd).
 */
final class CompileCommand
{
    /** Where Relayer expects `.psx` sources; a missing dir is skipped. */
    private const PSX_SOURCE_DIRS = ['src/Pages', 'src/Components'];

    /** Must match the cache dir {@see Relayer} reads. */
    private const PSX_CACHE_DIR = 'var/cache/psx';

    /**
     * @param list<string>               $args  argv after the `compile` verb (unused; reserved)
     * @param null|Closure(string): void $write line writer; defaults to STDOUT
     * @param null|string                $cwd   project root; defaults to getcwd()
     *
     * @return int 0 success; otherwise the exit code of the failing step
     */
    public static function run(array $args, ?Closure $write = null, ?string $cwd = null): int
    {
        $write ??= static function (string $line): void {
            \fwrite(\STDOUT, $line . "\n");
        };

        $root = \rtrim('' !== (string) $cwd ? (string) $cwd : (\getcwd() ?: '.'), '/');

        $write('[1/3] .psx -> PHP');
        if (0 !== ($status = self::compilePsx($root, $write))) {
            return $status;
        }

        $write('[2/3] routes -> PHP');
        if (0 !== ($status = RoutesCompileCommand::run([], $write, $root))) {
            return $status;
        }

        $write('[3/3] DI container -> PHP');

        return ContainerCompileCommand::run([], $write, $root);
    }

    /**
     * use-php's compiler prints straight to STDOUT; buffer it and replay
     * through the injected writer so this command stays testable and its
     * output lands in the same stream as the other two steps.
     *
     * @param Closure(string): void $write
     */
    private static function compilePsx(string $root, Closure $write): int
    {
        $paths = [];
        foreach (self::PSX_SOURCE_DIRS as $dir) {
            if (\is_dir($root . '/' . $dir)) {
                $paths[] = $root . '/' . $dir;
            }
        }

        if ([] === $paths) {
            $write('No src/Pages or src/Components directory found; skipping .psx.');

            return 0;
        }

        \ob_start();

        try {
            $status = (new PsxCompileCommand())->run(
                ['--cache=' . $root . '/' . self::PSX_CACHE_DIR, ...$paths],
                $root,
            );
        } catch (Throwable $e) {
            \ob_end_clean();
            $write('Could not compile .psx: ' . $e->getMessage());

            return 1;
        }

        $output = (string) \ob_get_clean();
        foreach (\explode("\n", \rtrim($output, "\n")) as $line) {
            if ('' !== $line) {
                $write($line);
            }
        }

        return $status;
    }
}
