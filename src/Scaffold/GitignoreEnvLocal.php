<?php

declare(strict_types=1);

namespace Polidog\Relayer\Scaffold;

/**
 * Keeps a `.env.local` that sets `APP_ENV=dev` out of Git.
 *
 * `init` and `upgrade` write that file, and a project can bring its own
 * `.gitignore` that does not list it. Committed and deployed, it would turn
 * production into dev and expose the profiler and stack traces.
 *
 * Both commands check before they write, never after: a write followed by a
 * failed check would leave dev mode behind, and the retry would find it in
 * place and have no reason to check again.
 *
 * @internal
 */
final class GitignoreEnvLocal
{
    public const string RULE = '/.env.local';

    public const string ALREADY_IGNORED = 'ignored';
    public const string ADDED = 'added';
    public const string CREATED = 'created';

    /**
     * @return null|self::ADDED|self::ALREADY_IGNORED|self::CREATED null when `.gitignore` could not be read or written
     */
    public static function ensure(string $root): ?string
    {
        $path = $root . '/.gitignore';

        if (!\file_exists($path)) {
            return false === @\file_put_contents($path, self::RULE . "\n") ? null : self::CREATED;
        }

        $contents = @\file_get_contents($path);
        if (false === $contents) {
            return null;
        }

        if (self::ignores($contents)) {
            return self::ALREADY_IGNORED;
        }

        $separator = '' === $contents || \str_ends_with($contents, "\n") ? '' : "\n";

        // Appended rather than rewritten, so the file keeps its permissions.
        return false === @\file_put_contents($path, $separator . self::RULE . "\n", \FILE_APPEND) ? null : self::ADDED;
    }

    /**
     * Whether Git tracks `.env.local` in `$root`'s work tree, including one
     * deleted from the checkout but still in the index. `.gitignore` does
     * not apply to a tracked file, so dev mode written there would be
     * committed with the next change.
     *
     * False when git is missing or `$root` is not in a work tree: then
     * there is nothing to commit it to.
     */
    public static function isTracked(string $root): bool
    {
        if (!\function_exists('proc_open')) {
            return false;
        }

        $process = @\proc_open(
            ['git', 'ls-files', '--error-unmatch', '--', '.env.local'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        if (!\is_resource($process)) {
            return false;
        }

        foreach ($pipes as $index => $pipe) {
            if (0 !== $index) {
                \stream_get_contents($pipe);
            }
            \fclose($pipe);
        }

        return 0 === \proc_close($process);
    }

    /**
     * The lines `init` and `upgrade` print when {@see isTracked()} stops them.
     *
     * @return list<string>
     */
    public static function trackedWarning(string $then): array
    {
        return [
            'Did not write APP_ENV=dev to .env.local: the file is tracked by Git,',
            'and .gitignore does not apply to tracked files. It would be committed',
            'and deployed, turning production into dev (profiler, stack traces).',
            'Untrack it, keeping your copy: git rm --cached .env.local',
            'Then commit that, and ' . $then,
        ];
    }

    /**
     * Whether the root `.gitignore` rules leave `.env.local` ignored. Git's
     * own order applies: the last matching rule wins, and `!` re-includes.
     */
    public static function ignores(string $gitignore): bool
    {
        $ignored = false;

        foreach (\preg_split('/\R/', $gitignore) ?: [] as $line) {
            $rule = \rtrim($line);
            if ('' === $rule || \str_starts_with($rule, '#')) {
                continue;
            }

            $negated = \str_starts_with($rule, '!');
            $pattern = \ltrim($negated ? \substr($rule, 1) : $rule, '/');

            // A trailing slash matches directories only.
            if ('' === $pattern || \str_ends_with($pattern, '/')) {
                continue;
            }

            if (\fnmatch($pattern, '.env.local', \FNM_PERIOD) || \fnmatch($pattern, '.env.local')) {
                $ignored = !$negated;
            }
        }

        return $ignored;
    }

    /**
     * The line `init` and `upgrade` print for an outcome, if any.
     */
    public static function report(string $outcome): ?string
    {
        return match ($outcome) {
            self::ADDED => '  ~ .gitignore (added ' . self::RULE . ', so the dev override is never committed)',
            self::CREATED => '  + .gitignore (' . self::RULE . ', so the dev override is never committed)',
            default => null,
        };
    }
}
