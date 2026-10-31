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
     * Make real process variables visible to Symfony Dotenv, so they win
     * over `.env` files.
     *
     * Dotenv decides whether a variable already exists by looking only at
     * `$_ENV` / `$_SERVER`, never at `getenv()`. When `variables_order` lacks
     * `E` and the SAPI does not import the environment into `$_SERVER` (the
     * built-in server), it therefore cannot see a real `APP_ENV=prod` and
     * overwrites it with the `.env` value.
     *
     * Values go into `$_ENV` only, which is enough for Dotenv and
     * {@see read()}. `$_SERVER` is where {@see Http\Request} and the auth
     * layer read request data from, so a variable named `REMOTE_ADDR` or
     * `CONTENT_TYPE` copied there would pass as part of the request.
     *
     * Skipped:
     * - names already in `$_ENV` / `$_SERVER`, which keep their precedence;
     * - `HTTP_*`, because a CGI SAPI puts request headers in the process
     *   environment, where a client could otherwise set them (httpoxy);
     * - `SYMFONY_DOTENV_*`, because Dotenv overwrites any variable listed in
     *   `SYMFONY_DOTENV_VARS`, which would undo the point of this method.
     */
    public static function importProcessEnvironment(): void
    {
        foreach (\getenv() as $name => $value) {
            // PHP turns a numeric variable name into an int array key.
            $name = (string) $name;
            if (\str_starts_with($name, 'HTTP_') || \str_starts_with($name, 'SYMFONY_DOTENV_')) {
                continue;
            }
            if (!isset($_ENV[$name]) && !isset($_SERVER[$name])) {
                $_ENV[$name] = $value;
            }
        }
    }

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
