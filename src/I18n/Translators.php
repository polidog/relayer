<?php

declare(strict_types=1);

namespace Polidog\Relayer\I18n;

use Polidog\Relayer\Di\ContainerFactory;
use Polidog\Relayer\Validation\Validator;

/**
 * Process-wide holder for the "ambient" {@see Translator}.
 *
 * The {@see Validator} schemas are value objects
 * constructed by user code with `Validator::string()->min(3)` — outside the
 * DI container — so they cannot take a Translator dependency. They reach
 * the active translator through {@see default()} instead.
 *
 * {@see ContainerFactory} / `AppRouter` call
 * {@see setDefault()} during boot/dispatch so the same container-built
 * Translator (project catalogs + the request's resolved locale) backs
 * validation messages. When nothing has been set — standalone validation,
 * unit tests, CLI — {@see default()} lazily builds a framework-only,
 * English-default Translator, which yields exactly the pre-i18n English
 * strings (so existing behavior is preserved).
 *
 * This mirrors the static ambient state the framework already relies on
 * elsewhere (`RenderContext::setApp()`, `ComponentState`); it is the
 * pragmatic seam for translating code that is intentionally built without
 * DI.
 */
final class Translators
{
    private static ?Translator $default = null;

    public static function setDefault(Translator $translator): void
    {
        self::$default = $translator;
    }

    public static function default(): Translator
    {
        return self::$default ??= Translator::framework();
    }

    /**
     * Drop the ambient translator. Tests call this so a Translator
     * registered by one test cannot leak its locale into the next.
     */
    public static function reset(): void
    {
        self::$default = null;
    }
}
