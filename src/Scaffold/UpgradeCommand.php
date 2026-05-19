<?php

declare(strict_types=1);

namespace Polidog\Relayer\Scaffold;

use Closure;
use JsonException;

/**
 * `relayer upgrade` — migrate a project scaffolded by an older framework
 * version up to the current {@see Scaffold::STRUCTURE_VERSION}.
 *
 * The counterpart to {@see InitCommand}: `init` stamps
 * `extra.relayer.structure_version` once and never advances it (so it can
 * always tell which shape a project was generated against); `upgrade` reads
 * that marker, applies the {@see Scaffold::migrations()} steps for every
 * version between it and the installed framework's, and *then* advances the
 * marker. It is the only command that moves it.
 *
 * Same idempotent, non-destructive, testable shape as the other scaffold
 * commands (injected line writer + cwd, skip-if-exists writes, atomic
 * composer.json rewrite). Scope is deliberately just the structure deltas
 * plus the marker — it does NOT reconcile composer scripts/autoload; re-run
 * `relayer init` for those (it is additive and safe).
 */
final class UpgradeCommand
{
    /**
     * @param list<string>               $args  argv after the `upgrade` verb (unused; reserved)
     * @param null|Closure(string): void $write line writer; defaults to STDOUT
     * @param null|string                $cwd   project root; defaults to getcwd()
     *
     * @return int 0 success / already current, 1 I/O or state error, 2 misuse
     */
    public static function run(array $args, ?Closure $write = null, ?string $cwd = null): int
    {
        $write ??= static function (string $line): void {
            \fwrite(\STDOUT, $line . "\n");
        };

        $root = \rtrim('' !== (string) $cwd ? (string) $cwd : (\getcwd() ?: '.'), '/');
        $composerPath = $root . '/composer.json';

        if (!\is_file($composerPath)) {
            $write('No composer.json found in the current directory.');
            $write('Run `relayer upgrade` from a Relayer project root.');

            return 2;
        }

        $raw = \file_get_contents($composerPath);
        if (false === $raw) {
            $write('Could not read composer.json.');

            return 1;
        }

        try {
            $composer = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $write('composer.json is not valid JSON: ' . $e->getMessage());

            return 1;
        }

        // Mirror InitCommand: a JSON object decodes to an assoc array; a
        // non-empty JSON list is is_array() but is not a composer.json
        // object. `{}` -> `[]` is a valid empty object.
        if (!\is_array($composer) || (\array_is_list($composer) && [] !== $composer)) {
            $write('composer.json does not contain a JSON object.');

            return 1;
        }

        $extra = \is_array($composer['extra'] ?? null) ? $composer['extra'] : [];
        $relayer = \is_array($extra['relayer'] ?? null) ? $extra['relayer'] : [];

        // `composerPatch()` has stamped this since the first scaffolder
        // (v1), so an absent marker means the project was never
        // `relayer init`'d (or the marker was hand-removed). Refuse and
        // point at init rather than guess a version.
        if (!\array_key_exists('structure_version', $relayer)) {
            $write('This project has no extra.relayer.structure_version marker.');
            $write('It was not created by `relayer init` (or the marker was removed).');
            $write('Run `relayer init` to stamp the current structure first.');

            return 2;
        }

        $recorded = $relayer['structure_version'];
        if (!\is_int($recorded)) {
            $write(\sprintf(
                'extra.relayer.structure_version must be an integer, got %s.',
                \get_debug_type($recorded),
            ));

            return 1;
        }

        $target = Scaffold::STRUCTURE_VERSION;

        if ($recorded === $target) {
            $write(\sprintf('Already at structure version %d; nothing to upgrade.', $target));

            return 0;
        }

        if ($recorded > $target) {
            $write(\sprintf(
                'Project structure version %d is newer than this framework supports (%d).',
                $recorded,
                $target,
            ));
            $write('Update polidog/relayer (`composer update`), then re-run `relayer upgrade`.');

            return 1;
        }

        if (0 !== ($status = self::applyMigrations($root, $recorded, $target, $write))) {
            return $status;
        }

        return self::stampVersion($composerPath, $composer, $extra, $relayer, $recorded, $target, $write);
    }

    /**
     * Write every file introduced between `$recorded` (exclusive) and
     * `$target` (inclusive), skip-if-exists. Mirrors {@see
     * InitCommand::writeFiles()} reporting so the two commands feel the same.
     *
     * @param Closure(string): void $write
     */
    private static function applyMigrations(string $root, int $recorded, int $target, Closure $write): int
    {
        $files = Scaffold::files();
        $migrations = Scaffold::migrations();

        $created = [];
        $skipped = [];

        for ($v = $recorded + 1; $v <= $target; ++$v) {
            foreach ($migrations[$v] ?? [] as $relative) {
                // migrations() must only name files() keys; if it ever
                // desyncs, fail loud instead of writing an empty file.
                if (!isset($files[$relative])) {
                    $write(\sprintf('Internal error: no scaffold content for "%s".', $relative));

                    return 1;
                }

                $path = $root . '/' . $relative;

                if (\file_exists($path)) {
                    $skipped[] = $relative;

                    continue;
                }

                $dir = \dirname($path);
                if (!\is_dir($dir) && !@\mkdir($dir, 0o755, true) && !\is_dir($dir)) {
                    $write(\sprintf('Could not create directory "%s".', $dir));

                    return 1;
                }

                if (false === @\file_put_contents($path, $files[$relative])) {
                    $write(\sprintf('Could not write "%s".', $relative));

                    return 1;
                }

                $created[] = $relative;
            }
        }

        \sort($created);
        \sort($skipped);

        if ([] !== $created) {
            $write(\sprintf('Created %d files:', \count($created)));
            foreach ($created as $relative) {
                $write('  + ' . $relative);
            }
        }

        if ([] !== $skipped) {
            $write(\sprintf('Skipped %d existing files:', \count($skipped)));
            foreach ($skipped as $relative) {
                $write('  = ' . $relative);
            }
        }

        return 0;
    }

    /**
     * Advance `extra.relayer.structure_version` to `$target` and atomically
     * rewrite composer.json — the one mutation `init` deliberately refuses.
     * `$extra`/`$relayer` are the already-extracted assoc blocks (the marker
     * read proved both were real arrays), so sibling keys are preserved.
     *
     * @param array<array-key, mixed> $composer the decoded composer.json object
     * @param array<array-key, mixed> $extra    its `extra` block (assoc)
     * @param array<array-key, mixed> $relayer  its `extra.relayer` block (assoc)
     * @param Closure(string): void   $write
     */
    private static function stampVersion(
        string $composerPath,
        array $composer,
        array $extra,
        array $relayer,
        int $recorded,
        int $target,
        Closure $write,
    ): int {
        $relayer['structure_version'] = $target;
        $extra['relayer'] = $relayer;
        $composer['extra'] = $extra;

        try {
            $encoded = \json_encode(
                $composer,
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
            ) . "\n";
        } catch (JsonException $e) {
            $write('Could not re-encode composer.json: ' . $e->getMessage());

            return 1;
        }

        // Atomic write: sibling temp file (same dir => same filesystem =>
        // atomic rename), exactly as InitCommand does, so a crash mid-write
        // never truncates the user's manifest.
        $tmp = $composerPath . '.relayer-tmp-' . \bin2hex(\random_bytes(4));
        if (false === @\file_put_contents($tmp, $encoded)) {
            @\unlink($tmp);
            $write('Could not write composer.json.');

            return 1;
        }

        if (!@\rename($tmp, $composerPath)) {
            @\unlink($tmp);
            $write('Could not write composer.json.');

            return 1;
        }

        $write(\sprintf('Upgraded structure version %d -> %d.', $recorded, $target));
        $write('');
        $write('Next steps:');
        // `composer install` (not dump-autoload) so any newly added
        // post-*-cmd / autoload entry a project still lacks is picked up
        // and the usePHP asset publisher runs.
        $write('  composer install');

        return 0;
    }
}
