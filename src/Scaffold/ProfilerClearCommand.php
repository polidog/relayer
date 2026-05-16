<?php

declare(strict_types=1);

namespace Polidog\Relayer\Scaffold;

use Closure;
use Polidog\Relayer\Profiler\FileProfilerStorage;
use Polidog\Relayer\Relayer;

/**
 * `relayer profiler:clear` — delete the dev profiler's stored profiles.
 *
 * The profiler persists one JSON document per request as
 * `{token}.json` under {@see Relayer::PROFILER_CACHE_DIR} (the dev wiring
 * in {@see Relayer} binds {@see FileProfilerStorage} to that same path).
 * This command clears off the very same constant so the written and the
 * cleared directory cannot drift. It wipes those files so `/_profiler`
 * starts from a clean slate.
 *
 * Same testable shape as {@see InitCommand} / {@see RoutesCommand} — injected
 * line writer and cwd, no STDOUT/chdir coupling.
 *
 * Idempotent and conservative by contract:
 *  - a missing cache directory is success (nothing to clear), not an
 *    error — re-running is always safe,
 *  - only `*.json` files are removed (exactly the shape the storage writes);
 *    the directory itself and any other files a user dropped in are left
 *    alone (the dir is recreated on the next dev request anyway),
 *  - a failed unlink is reported and yields exit 1 rather than a silent
 *    partial clear.
 */
final class ProfilerClearCommand
{
    /**
     * @param list<string>               $args  argv after the `profiler:clear` verb (unused; reserved)
     * @param null|Closure(string): void $write line writer; defaults to STDOUT
     * @param null|string                $cwd   project root; defaults to getcwd()
     *
     * @return int 0 success (incl. nothing to clear), 1 when a profile could not be removed
     */
    public static function run(array $args, ?Closure $write = null, ?string $cwd = null): int
    {
        $write ??= static function (string $line): void {
            \fwrite(\STDOUT, $line . "\n");
        };

        $root = \rtrim('' !== (string) $cwd ? (string) $cwd : (\getcwd() ?: '.'), '/');
        // Same path the dev wiring binds FileProfilerStorage to — off the
        // one constant so the cleared dir and the written dir cannot drift.
        $dir = $root . '/' . Relayer::PROFILER_CACHE_DIR;

        if (!\is_dir($dir)) {
            $write(\sprintf(
                'Profiler cache is already empty (%s not present).',
                Relayer::PROFILER_CACHE_DIR,
            ));

            return 0;
        }

        $files = \glob($dir . '/*.json') ?: [];

        if ([] === $files) {
            $write('Profiler cache is already empty.');

            return 0;
        }

        $removed = 0;
        $failed = [];
        foreach ($files as $file) {
            if (@\unlink($file)) {
                ++$removed;

                continue;
            }
            $failed[] = \basename($file);
        }

        if ([] !== $failed) {
            $write(\sprintf(
                'Removed %d of %d profile(s); could not delete: %s',
                $removed,
                \count($files),
                \implode(', ', $failed),
            ));

            return 1;
        }

        $write(\sprintf('Removed %d profile(s) from %s.', $removed, Relayer::PROFILER_CACHE_DIR));

        return 0;
    }
}
