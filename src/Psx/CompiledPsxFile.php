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
 * and only when none of the symbols it would declare exists yet (or the scan
 * cannot tell): PHP cannot replace a declaration, so such a file keeps the
 * value it first returned until the worker restarts.
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
            $reason = null === $declared
                ? 'its declarations could not be checked against what this process already has'
                : 'it declares ' . \implode(', ', $declared) . ', which this process already has and PHP cannot redeclare';
            if (null === $loaded['hash']) {
                throw new RuntimeException("Compiled PSX file {$file} cannot run again in this process after its failed first run: {$reason}. Restart the PHP process to load it.");
            }

            \error_log("[relayer] Compiled PSX file {$file} changed, but {$reason}. Still serving the version loaded first. Restart the worker to load the new one.");
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
     * The functions, classes, interfaces, traits and enums that running the
     * file would declare again: those it declares anywhere in its file-scope
     * code (an `if`, `else`, `try`, loop, `switch` or bare block included)
     * that already exist. A body of a function, closure or class is not run by
     * the file, so what it holds is not looked at. A declaration directly
     * inside `if (!function_exists('its name'))` (or the `class_exists`,
     * `interface_exists`, `trait_exists`, `enum_exists` check for its kind) is
     * skipped by its guard. Null when the file cannot be read reliably enough
     * to tell, which a caller has to treat as a conflict; empty for a file
     * PHP will refuse to compile, so the require reports the error.
     *
     * @return null|list<string>
     */
    private static function declaredSymbols(string $file): ?array
    {
        $contents = \file_get_contents($file);
        if (false === $contents) {
            return null;
        }
        $tokens = \array_values(\array_filter(
            PhpToken::tokenize($contents),
            static fn (PhpToken $token): bool => !$token->isIgnorable(),
        ));

        $existing = [];
        $namespace = '';

        // One entry per open brace: null for a block the file runs, false for
        // a body it does not, or the lowercased name an `if` guard protects.
        /** @var list<null|false|string> $scopes */
        $scopes = [];
        $opaque = 0;
        // Bodies announced by `function` / `class` whose `{` is still ahead.
        $pendingBodies = 0;
        $pendingGuard = null;
        $count = \count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token->is([\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                // Interpolation in a string: never holds a declaration.
                $scopes[] = false;
                ++$opaque;

                continue;
            }
            if ($token->is('{')) {
                if ($pendingBodies > 0) {
                    --$pendingBodies;
                    $scopes[] = false;
                    ++$opaque;
                } else {
                    $scopes[] = null !== $pendingGuard && $pendingGuard[0] === $i ? $pendingGuard[1] : null;
                }
                $pendingGuard = null;

                continue;
            }
            if ($token->is('}')) {
                if ([] === $scopes) {
                    // Unbalanced: PHP fails to compile it before it declares anything.
                    return [];
                }
                if (false === \array_pop($scopes)) {
                    --$opaque;
                }

                continue;
            }
            if (0 !== $opaque) {
                continue;
            }

            $next = $tokens[$i + 1] ?? null;
            $previous = $tokens[$i - 1] ?? null;
            if ($token->is(\T_NAMESPACE) && null !== $next && $next->is([\T_STRING, \T_NAME_QUALIFIED, '{'])) {
                $namespace = $next->is('{') ? '' : $next->text . '\\';

                continue;
            }
            if ($token->is(\T_USE) && null !== $next && !$next->is('(')) {
                // An import, possibly grouped: its braces and `function` are not code.
                while ($i < $count && !$tokens[$i]->is(';')) {
                    ++$i;
                }

                continue;
            }
            if ($token->is(\T_IF)) {
                $pendingGuard = self::guard($tokens, $i, $namespace);

                continue;
            }
            if (null !== $previous && $previous->is([\T_DOUBLE_COLON, \T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR])) {
                // `Foo::class`, `$x->function`: a name, not a keyword.
                continue;
            }
            if (null !== $next && $next->is(':') && $token->is([\T_FUNCTION, \T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM])) {
                // A named argument such as `class: 1`.
                continue;
            }

            if ($token->is(\T_FUNCTION)) {
                ++$pendingBodies;
                $name = ($next?->is('&') ?? false) ? ($tokens[$i + 2] ?? null) : $next;
                if (null === $name || $name->is('(')) {
                    continue;
                }
                if (!$name->is(\T_STRING)) {
                    return null;
                }
                $symbol = $namespace . $name->text;
                if (\function_exists($symbol) && !self::isGuarded($scopes, 'function', $symbol)) {
                    $existing[] = $symbol;
                }
            } elseif ($token->is([\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM])) {
                ++$pendingBodies;
                if (null === $next || !$next->is(\T_STRING)) {
                    // An anonymous class: `new class`, `new readonly class`.
                    continue;
                }
                $symbol = $namespace . $next->text;
                $kind = \strtolower($token->text);
                if ((\class_exists($symbol, false) || \interface_exists($symbol, false) || \trait_exists($symbol, false)) && !self::isGuarded($scopes, $kind, $symbol)) {
                    $existing[] = $symbol;
                }
            }
        }

        // A brace left open fails to compile too, and the require reports it.
        return [] === $scopes ? $existing : [];
    }

    /**
     * The `{` of an `if (!kind_exists('name'))` at `$if` and what it guards,
     * as `[brace index, "kind:lowercased name"]`; null for any other `if`.
     *
     * @param list<PhpToken> $tokens
     *
     * @return null|array{int, string}
     */
    private static function guard(array $tokens, int $if, string $namespace): ?array
    {
        $at = static fn (int $offset): ?PhpToken => $tokens[$if + $offset] ?? null;
        $check = $at(3);
        $literal = $at(5);
        if (!($at(1)?->is('(') ?? false) || !($at(2)?->is('!') ?? false) || null === $check || !$check->is([\T_STRING, \T_NAME_FULLY_QUALIFIED])
            || !($at(4)?->is('(') ?? false) || null === $literal || !$literal->is(\T_CONSTANT_ENCAPSED_STRING)) {
            return null;
        }

        $offset = 6;
        if ($at($offset)?->is(',') ?? false) {
            // `class_exists('X', false)`: whether to autoload does not change the answer here.
            $flag = $at($offset + 1);
            if (null === $flag || !\in_array(\strtolower($flag->text), ['true', 'false', '\true', '\false'], true)) {
                return null;
            }
            $offset += 2;
        }
        if (!($at($offset)?->is(')') ?? false) || !($at($offset + 1)?->is(')') ?? false) || !($at($offset + 2)?->is('{') ?? false)) {
            return null;
        }

        $function = \strtolower(\ltrim($check->text, '\\'));
        $kind = match ($function) {
            'function_exists' => 'function',
            'class_exists' => 'class',
            'interface_exists' => 'interface',
            'trait_exists' => 'trait',
            'enum_exists' => 'enum',
            default => null,
        };
        // Unqualified in a namespace, the call could reach a function of that namespace.
        if (null === $kind || ($check->is(\T_STRING) && '' !== $namespace && \function_exists($namespace . $function))) {
            return null;
        }

        $quote = $literal->text[0];
        $name = \substr($literal->text, 1, -1);
        $name = "'" === $quote ? \strtr($name, ['\\\\' => '\\', "\\'" => "'"]) : \stripcslashes($name);
        if ('"' === $quote && \str_contains($name, '$')) {
            return null;
        }

        return [$if + $offset + 2, $kind . ':' . \strtolower(\ltrim($name, '\\'))];
    }

    /**
     * Whether the innermost block around a declaration is the `if` that skips
     * it when `$symbol` exists. An enum is also a class to `class_exists`.
     *
     * @param list<null|false|string> $scopes
     */
    private static function isGuarded(array $scopes, string $kind, string $symbol): bool
    {
        $guard = [] === $scopes ? null : $scopes[\array_key_last($scopes)];
        if (!\is_string($guard)) {
            return false;
        }
        $name = \strtolower($symbol);

        return $guard === $kind . ':' . $name || ('enum' === $kind && $guard === 'class:' . $name);
    }
}
