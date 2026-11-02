<?php

declare(strict_types=1);

namespace Polidog\Relayer\Psx;

use Closure;
use ParseError;
use PhpToken;
use RuntimeException;

/**
 * Executes a compiled PSX file and keeps what it returned for the process.
 *
 * A component file may declare named functions or classes, so requiring it a
 * second time is a fatal "Cannot redeclare", and a worker lives through many
 * boots. Only a file whose content changed (a recompile in dev) runs again,
 * and only when none of the symbols it declares exists yet: PHP cannot replace
 * a declaration, so such a file keeps the value it first returned until the
 * worker restarts.
 */
final class CompiledPsxFile
{
    /**
     * `hash` is the content `value` came from, null while the file's first run
     * has not returned; `refused` is a newer content that could not be run.
     *
     * @var array<string, array{hash: ?string, value: mixed, refused: ?string}>
     */
    private static array $loaded = [];

    public static function load(string $file): mixed
    {
        $hash = \is_file($file) && \is_readable($file) ? \md5_file($file) : false;
        if (false === $hash) {
            throw new RuntimeException("Compiled PSX file not found: {$file}. Run `vendor/bin/rosanna-defer compile` to regenerate.");
        }

        $loaded = self::$loaded[$file] ?? null;
        if (null === $loaded) {
            // Marked before it runs: a file that throws may have declared already.
            self::$loaded[$file] = ['hash' => null, 'value' => null, 'refused' => null];
        } elseif (\in_array($hash, [$loaded['hash'], $loaded['refused']], true)) {
            return $loaded['value'];
        } elseif ([] !== $declared = self::declaredSymbols($file)) {
            // Only a file that already ran is scanned, so a first load costs nothing.
            $names = \implode(', ', $declared);
            if (null === $loaded['hash']) {
                throw new RuntimeException("Compiled PSX file {$file} cannot run again in this process: its failed first run already declared {$names}. Restart the PHP process to load it.");
            }

            \error_log("[relayer] Compiled PSX file {$file} changed, but it declares {$names}, which this process already has and PHP cannot redeclare. Still serving the version loaded first. Restart the worker to load the new one.");
            self::$loaded[$file]['refused'] = $hash;

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

        self::$loaded[$file] = ['hash' => $hash, 'value' => $value, 'refused' => null];

        return $value;
    }

    /**
     * The functions, classes, interfaces, traits and enums the file declares
     * at its top level that already exist. Declarations inside a block (an
     * `if (!function_exists(...))` guard, a function body) are not looked at.
     *
     * @return list<string>
     */
    private static function declaredSymbols(string $file): array
    {
        $tokens = \array_values(\array_filter(
            PhpToken::tokenize((string) \file_get_contents($file)),
            static fn (PhpToken $token): bool => !$token->isIgnorable(),
        ));

        $existing = [];
        $namespace = '';
        $depth = 0;
        $count = \count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token->is(['{', \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                ++$depth;

                continue;
            }
            if ($token->is('}')) {
                $depth = \max(0, $depth - 1);

                continue;
            }
            if (0 !== $depth) {
                continue;
            }

            $next = $tokens[$i + 1] ?? null;
            if ($token->is(\T_NAMESPACE) && null !== $next && $next->is([\T_STRING, \T_NAME_QUALIFIED, '{'])) {
                // A braced namespace's body is still the top level.
                $namespace = $next->is('{') ? '' : $next->text . '\\';
                $i += $next->is('{') ? 1 : (($tokens[$i + 2] ?? null)?->is('{') ? 2 : 1);

                continue;
            }
            if ($token->is(\T_USE) && null !== $next && !$next->is('(')) {
                // An import, possibly grouped: its braces and `function` are not code.
                while ($i < $count && !$tokens[$i]->is(';')) {
                    ++$i;
                }

                continue;
            }
            if (($tokens[$i - 1] ?? null)?->is([\T_DOUBLE_COLON, \T_NEW]) ?? false) {
                continue;
            }

            if ($token->is(\T_FUNCTION)) {
                $name = ($next?->is('&') ?? false) ? ($tokens[$i + 2] ?? null) : $next;
                if (null !== $name && $name->is(\T_STRING) && \function_exists($namespace . $name->text)) {
                    $existing[] = $namespace . $name->text;
                }
            } elseif ($token->is([\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM]) && null !== $next && $next->is(\T_STRING)) {
                $symbol = $namespace . $next->text;
                if (\class_exists($symbol, false) || \interface_exists($symbol, false) || \trait_exists($symbol, false)) {
                    $existing[] = $symbol;
                }
            }
        }

        return $existing;
    }
}
