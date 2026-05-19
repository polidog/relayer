<?php

declare(strict_types=1);

namespace Polidog\Relayer\I18n;

/**
 * Loads PHP-array message catalogs from disk.
 *
 * A catalog file is named `{locale}.php` and `return`s an array that may
 * be flat (`'relayer.http.404' => '...'`) or nested
 * (`'relayer' => ['http' => ['404' => '...']]`); nested arrays are
 * flattened to dot-keyed strings so the {@see Translator} only ever sees a
 * flat map.
 *
 * Two sources, merged with the project winning on key collision:
 *  - the framework's own `src/I18n/resources` (English + Japanese);
 *  - the consuming app's `<projectRoot>/translations` (convention dir).
 */
final class CatalogLoader
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function framework(): array
    {
        return self::loadDir(__DIR__ . '/resources');
    }

    /**
     * Framework catalogs overlaid with the project's `translations/` dir.
     * Project entries override framework keys for the same locale.
     *
     * @return array<string, array<string, string>>
     */
    public static function forProject(string $projectRoot): array
    {
        $catalogs = self::framework();

        $projectDir = \rtrim($projectRoot, '/') . '/translations';
        foreach (self::loadDir($projectDir) as $locale => $messages) {
            $catalogs[$locale] = \array_merge($catalogs[$locale] ?? [], $messages);
        }

        return $catalogs;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function loadDir(string $dir): array
    {
        if (!\is_dir($dir)) {
            return [];
        }

        $files = \glob($dir . '/*.php');
        if (false === $files) {
            return [];
        }

        $catalogs = [];
        foreach ($files as $file) {
            $locale = \basename($file, '.php');
            if ('' === $locale) {
                continue;
            }

            $loaded = require $file;
            if (!\is_array($loaded)) {
                continue;
            }

            /** @var array<array-key, mixed> $loaded */
            $catalogs[$locale] = self::flatten($loaded);
        }

        return $catalogs;
    }

    /**
     * @param array<array-key, mixed> $messages
     *
     * @return array<string, string>
     */
    private static function flatten(array $messages, string $prefix = ''): array
    {
        $flat = [];

        foreach ($messages as $key => $value) {
            $compound = '' === $prefix ? (string) $key : $prefix . '.' . $key;

            if (\is_array($value)) {
                foreach (self::flatten($value, $compound) as $nestedKey => $nestedValue) {
                    $flat[$nestedKey] = $nestedValue;
                }
            } elseif (\is_string($value)) {
                $flat[$compound] = $value;
            } elseif (\is_scalar($value)) {
                $flat[$compound] = (string) $value;
            }
        }

        return $flat;
    }
}
