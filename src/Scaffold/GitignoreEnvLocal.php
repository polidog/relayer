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
