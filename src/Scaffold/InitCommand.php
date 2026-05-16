<?php

declare(strict_types=1);

namespace Polidog\Relayer\Scaffold;

use Closure;
use JsonException;

/**
 * `relayer init` — lay the {@see Scaffold} skeleton into the *current*
 * project (one that has already `composer require`d the framework).
 *
 * Deliberately not built on symfony/console: the framework doesn't depend on
 * it and a two-verb tool doesn't justify pulling it in. All output goes
 * through an injected line writer and the working directory is injectable, so
 * the command is testable without touching STDOUT or chdir().
 *
 * Idempotent and non-destructive by contract:
 *  - existing skeleton files are never overwritten (reported as skipped),
 *  - the existing `composer.json` is patched additively — user values win,
 *    only missing keys / array members are added,
 *  - `extra.relayer.structure_version` is stamped only on the initial
 *    scaffold (when absent) and never advanced here, so a future `upgrade`
 *    can still tell which shape the project was generated against,
 *  - if `App\` is already mapped somewhere other than `src/`, the command
 *    refuses (it would otherwise emit an entrypoint Composer can't autoload).
 */
final class InitCommand
{
    private const USAGE = <<<'TXT'
        Relayer scaffolder

        Usage:
          relayer init       scaffold the project structure in the current directory
          relayer routes     list the routes discovered under src/Pages

        Run inside a project that has already required the framework
        (`composer require polidog/relayer`). Existing files are left
        untouched; composer.json is patched additively.
        TXT;

    /**
     * @param list<string>               $args  argv without the script name (e.g. `['init']`)
     * @param null|Closure(string): void $write line writer; defaults to STDOUT
     * @param null|string                $cwd   project root; defaults to getcwd()
     *
     * @return int process exit code (0 success, 1 I/O failure, 2 misuse)
     */
    public static function run(array $args, ?Closure $write = null, ?string $cwd = null): int
    {
        $write ??= static function (string $line): void {
            \fwrite(\STDOUT, $line . "\n");
        };

        $command = $args[0] ?? null;

        if (null === $command || \in_array($command, ['-h', '--help', 'help'], true)) {
            $write(self::USAGE);

            return null === $command ? 2 : 0;
        }

        if ('routes' === $command) {
            return RoutesCommand::run(\array_slice($args, 1), $write, $cwd);
        }

        if ('init' !== $command) {
            $write(\sprintf('Unknown command "%s".', $command));
            $write('');
            $write(self::USAGE);

            return 2;
        }

        $root = \rtrim('' !== (string) $cwd ? (string) $cwd : (\getcwd() ?: '.'), '/');
        $composerPath = $root . '/composer.json';

        if (!\is_file($composerPath)) {
            $write('No composer.json found in the current directory.');
            $write('Run `composer require polidog/relayer` first, then `relayer init`.');

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

        // A JSON object decodes to an assoc array; a non-empty JSON array
        // (e.g. `["x"]`) also passes is_array() but is not a composer.json
        // object — reject it rather than silently mutating a malformed root.
        // `{}` decodes to `[]`, which is a valid (empty) object, so the
        // empty-array case is allowed through.
        if (!\is_array($composer) || (\array_is_list($composer) && [] !== $composer)) {
            $write('composer.json does not contain a JSON object.');

            return 1;
        }

        if (null !== ($conflict = self::appAutoloadConflict($composer))) {
            $write('composer.json maps "App\" to "' . $conflict . '", not "src/".');
            $write('The scaffold writes src/AppConfigurator.php, and public/index.php');
            $write('expects App\AppConfigurator under src/. Point the App\ PSR-4');
            $write('mapping at "src/" (or remove it), then re-run `relayer init`.');

            return 1;
        }

        if (0 !== ($status = self::writeFiles($root, $write))) {
            return $status;
        }

        return self::patchComposer($composerPath, $composer, $write);
    }

    /**
     * If `composer.json` already maps the `App\` PSR-4 prefix somewhere
     * other than `src/`, return a human description of where it points;
     * otherwise null. The scaffold hard-codes `App\` => `src/` (the
     * generated `public/index.php` instantiates `App\AppConfigurator` from
     * `src/`), so any other mapping would yield a project Composer can't
     * autoload. Composer allows a prefix to map to a string or a list of
     * paths; `src` and `src/` are both accepted.
     *
     * @param array<array-key, mixed> $composer
     */
    private static function appAutoloadConflict(array $composer): ?string
    {
        $autoload = \is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [];
        $psr4 = \is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];

        if (!\array_key_exists('App\\', $psr4)) {
            return null;
        }

        $mapped = $psr4['App\\'];
        $paths = \is_array($mapped) ? \array_values($mapped) : [$mapped];

        foreach ($paths as $path) {
            if ('src' === $path || 'src/' === $path) {
                return null;
            }
        }

        $describe = static fn (mixed $p): string => \is_string($p) ? $p : \get_debug_type($p);

        return \implode(', ', \array_map($describe, $paths));
    }

    /**
     * @param Closure(string): void $write
     */
    private static function writeFiles(string $root, Closure $write): int
    {
        $created = [];
        $skipped = [];

        foreach (Scaffold::files() as $relative => $contents) {
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

            if (false === @\file_put_contents($path, $contents)) {
                $write(\sprintf('Could not write "%s".', $relative));

                return 1;
            }

            $created[] = $relative;
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
     * Merge {@see Scaffold::composerPatch()} into the decoded composer.json
     * without clobbering user values, then rewrite the file only if it
     * actually changed.
     *
     * @param array<array-key, mixed> $composer the decoded composer.json object
     * @param Closure(string): void   $write
     */
    private static function patchComposer(string $composerPath, array $composer, Closure $write): int
    {
        $patch = Scaffold::composerPatch();
        $changes = [];

        // autoload.psr-4: add the App\ prefix only if it is not already
        // mapped — never repoint a prefix the user configured.
        $autoload = \is_array($composer['autoload'] ?? null) ? $composer['autoload'] : [];
        $psr4 = \is_array($autoload['psr-4'] ?? null) ? $autoload['psr-4'] : [];
        if (!\array_key_exists('App\\', $psr4)) {
            $psr4['App\\'] = $patch['autoload']['psr-4']['App\\'];
            $autoload['psr-4'] = $psr4;
            $composer['autoload'] = $autoload;
            $changes[] = 'autoload.psr-4: added "App\\\" => "src/"';
        }

        // scripts.*: ensure the usePHP asset publisher runs on install/update.
        // Composer allows a script to be a string or a list; normalize to a
        // list and append only if the callback is absent.
        $scripts = \is_array($composer['scripts'] ?? null) ? $composer['scripts'] : [];
        foreach ($patch['scripts'] as $hook => $callbacks) {
            $existing = $scripts[$hook] ?? [];
            $list = \is_array($existing) ? \array_values($existing) : [$existing];

            foreach ($callbacks as $callback) {
                if (!\in_array($callback, $list, true)) {
                    $list[] = $callback;
                    $changes[] = \sprintf('scripts.%s: added %s', $hook, $callback);
                }
            }

            $scripts[$hook] = $list;
        }
        $composer['scripts'] = $scripts;

        // extra.relayer.structure_version: stamped ONLY on the initial
        // scaffold (when the key is absent). It records the shape the
        // project was generated against; advancing it is a future
        // `upgrade`'s job. Bumping it here on a re-run after a framework
        // upgrade would make `upgrade` believe the project was already
        // migrated and skip the migration, so init never touches an
        // existing value.
        $extra = \is_array($composer['extra'] ?? null) ? $composer['extra'] : [];
        $relayer = \is_array($extra['relayer'] ?? null) ? $extra['relayer'] : [];
        if (!\array_key_exists('structure_version', $relayer)) {
            $version = $patch['extra']['relayer']['structure_version'];
            $relayer['structure_version'] = $version;
            $extra['relayer'] = $relayer;
            $composer['extra'] = $extra;
            $changes[] = \sprintf('extra.relayer.structure_version: set to %d', $version);
        }

        if ([] === $changes) {
            $write('composer.json already up to date.');
        } else {
            try {
                $encoded = \json_encode(
                    $composer,
                    \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
                ) . "\n";
            } catch (JsonException $e) {
                $write('Could not re-encode composer.json: ' . $e->getMessage());

                return 1;
            }

            // Atomic write: a direct file_put_contents on the user's
            // manifest can leave it truncated if the process dies mid-write.
            // Write a sibling temp file (same directory ⇒ same filesystem ⇒
            // atomic rename) and swap it in.
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

            $write(\sprintf('Patched composer.json (%d changes):', \count($changes)));
            foreach ($changes as $change) {
                $write('  ~ ' . $change);
            }
        }

        $write('');
        $write('Next steps:');
        // `composer install` (not dump-autoload) so the App\ autoload AND
        // the just-added post-install-cmd both apply — the latter publishes
        // public/usephp.js, which the default document references.
        $write('  composer install');
        $write('  php -S 127.0.0.1:8000 -t public');

        return 0;
    }
}
