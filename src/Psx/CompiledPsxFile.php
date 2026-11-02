<?php

declare(strict_types=1);

namespace Polidog\Relayer\Psx;

use Closure;
use ParseError;
use RuntimeException;

/**
 * Executes a compiled PSX file and keeps what it returned for the process.
 *
 * A component file may declare named functions or classes, so requiring it a
 * second time is a fatal "Cannot redeclare", and a worker lives through many
 * boots. Only a file whose content changed (a recompile in dev) runs again.
 */
final class CompiledPsxFile
{
    /** @var array<string, array{hash: string, value: mixed}> */
    private static array $loaded = [];

    public static function load(string $file): mixed
    {
        $hash = \is_file($file) && \is_readable($file) ? \md5_file($file) : false;
        if (false === $hash) {
            throw new RuntimeException("Compiled PSX file not found: {$file}. Run `vendor/bin/rosanna-defer compile` to regenerate.");
        }

        if (($loaded = self::$loaded[$file] ?? null) !== null && $loaded['hash'] === $hash) {
            return $loaded['value'];
        }

        // A closure made in a method keeps that class's scope even when static,
        // and everything the file declares would inherit it.
        $require = Closure::bind(static fn (string $__file): mixed => require $__file, null, null);

        try {
            $value = $require($file);
        } catch (ParseError $e) {
            throw new RuntimeException("Compiled PSX file is invalid PHP: {$file}. Run `vendor/bin/rosanna-defer compile` to regenerate. ({$e->getMessage()})", previous: $e);
        }

        self::$loaded[$file] = ['hash' => $hash, 'value' => $value];

        return $value;
    }
}
