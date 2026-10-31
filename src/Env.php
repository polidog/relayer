<?php

declare(strict_types=1);

namespace Polidog\Relayer;

/**
 * The one place the framework reads environment variables.
 *
 * Symfony Dotenv populates `$_ENV` / `$_SERVER` (not `getenv()`), while a
 * real process variable may be visible only through `getenv()` when
 * `variables_order` lacks `E` (php.ini-production, the built-in server), so
 * every lookup walks all three. Values are trimmed: secrets and settings
 * sourced from files routinely pick up a trailing newline, and a value that
 * is blank after trimming is treated as unset.
 */
final class Env
{
    /**
     * The trimmed value of `$name`, or null when it is unset or blank.
     */
    public static function read(string $name): ?string
    {
        $raw = $_ENV[$name] ?? $_SERVER[$name] ?? \getenv($name);
        if (!\is_string($raw)) {
            return null;
        }
        $value = \trim($raw);

        return '' === $value ? null : $value;
    }

    /**
     * A comma-separated value as a list; entries are trimmed and empty ones
     * dropped. `[]` when the variable is unset or blank.
     *
     * @return list<string>
     */
    public static function readList(string $name): array
    {
        $raw = self::read($name);
        if (null === $raw) {
            return [];
        }

        $out = [];
        foreach (\explode(',', $raw) as $entry) {
            $entry = \trim($entry);
            if ('' !== $entry) {
                $out[] = $entry;
            }
        }

        return $out;
    }
}
