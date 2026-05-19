<?php

declare(strict_types=1);

namespace Polidog\Relayer\I18n;

/**
 * Dependency-free message translator.
 *
 * Holds one flat `key => message` catalog per locale and resolves a key
 * against the active locale, then a normalized-primary fallback
 * (`ja-JP` → `ja`), then the configured fallback locale, and finally the
 * key itself — so a missing translation degrades to something printable
 * rather than throwing.
 *
 * Placeholders are `{name}` and substituted from the `$params` map.
 * {@see transChoice()} adds count-based selection across a pipe-separated
 * message (`"one|other"`) using {@see PluralRules}.
 *
 * The active locale is mutable ({@see setLocale()}) because one Translator
 * instance is shared for a whole request and the resolved locale is only
 * known mid-dispatch.
 */
final class Translator
{
    /** @var array<string, array<string, string>> */
    private array $catalogs;

    private string $locale;

    private readonly string $defaultLocale;

    private readonly string $fallbackLocale;

    /**
     * @param array<string, array<string, string>> $catalogs       locale => (key => message)
     * @param string                               $defaultLocale  active locale until {@see setLocale()}
     * @param null|string                          $fallbackLocale looked up when a key is missing in the
     *                                                             active locale; defaults to $defaultLocale
     */
    public function __construct(array $catalogs, string $defaultLocale = 'en', ?string $fallbackLocale = null)
    {
        $this->catalogs = $catalogs;
        $this->defaultLocale = '' !== $defaultLocale ? $defaultLocale : 'en';
        $this->fallbackLocale = null !== $fallbackLocale && '' !== $fallbackLocale
            ? $fallbackLocale
            : $this->defaultLocale;
        $this->locale = $this->defaultLocale;
    }

    /**
     * A Translator backed only by the framework's own catalogs
     * (`src/I18n/resources`), English-default. Used as the standalone
     * fallback for {@see Translators::default()} so validation messages
     * still localize when the DI container has not booted (e.g. unit
     * tests, CLI).
     */
    public static function framework(): self
    {
        return new self(CatalogLoader::framework(), 'en', 'en');
    }

    /**
     * Build the request-scoped Translator the DI container exposes: the
     * framework catalogs overlaid with the app's `<projectRoot>/translations`
     * dir. The active locale starts at $defaultLocale and is narrowed to the
     * request's resolved locale by AppRouter mid-dispatch.
     */
    public static function createForProject(
        string $projectRoot,
        string $defaultLocale = 'en',
        string $fallbackLocale = 'en',
    ): self {
        return new self(
            CatalogLoader::forProject($projectRoot),
            $defaultLocale,
            $fallbackLocale,
        );
    }

    public function setLocale(string $locale): void
    {
        if ('' !== $locale) {
            $this->locale = $locale;
        }
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    /**
     * Translate a key. Unknown keys return the key itself (after
     * placeholder substitution) so a missing message is visible but never
     * fatal.
     *
     * @param array<string, float|int|string> $params
     */
    public function trans(string $key, array $params = [], ?string $locale = null): string
    {
        $message = $this->lookup($key, $locale ?? $this->locale) ?? $key;

        return $this->interpolate($message, $params);
    }

    /**
     * Translate a pluralized key. The message is split on `|` and the form
     * is chosen by {@see PluralRules}; `{count}` is auto-supplied when not
     * already present in `$params`.
     *
     * @param array<string, float|int|string> $params
     */
    public function transChoice(string $key, int $count, array $params = [], ?string $locale = null): string
    {
        $resolved = $locale ?? $this->locale;
        $message = $this->lookup($key, $resolved) ?? $key;

        $forms = \explode('|', $message);
        $index = PluralRules::index($resolved, $count);
        if ($index < 0 || $index >= \count($forms)) {
            $index = \count($forms) - 1;
        }

        $params['count'] ??= $count;

        return $this->interpolate($forms[$index], $params);
    }

    public function has(string $key, ?string $locale = null): bool
    {
        return null !== $this->lookup($key, $locale ?? $this->locale);
    }

    private function lookup(string $key, string $locale): ?string
    {
        foreach ($this->candidateLocales($locale) as $candidate) {
            if (isset($this->catalogs[$candidate][$key])) {
                return $this->catalogs[$candidate][$key];
            }
        }

        return null;
    }

    /**
     * Lookup order for a locale: exact tag, its normalized primary subtag,
     * then the configured fallback locale (deduplicated).
     *
     * @return list<string>
     */
    private function candidateLocales(string $locale): array
    {
        $chain = [$locale];

        $primary = LocaleNegotiator::normalize($locale);
        if ('' !== $primary && !\in_array($primary, $chain, true)) {
            $chain[] = $primary;
        }

        if (!\in_array($this->fallbackLocale, $chain, true)) {
            $chain[] = $this->fallbackLocale;
        }

        return $chain;
    }

    /**
     * @param array<string, float|int|string> $params
     */
    private function interpolate(string $message, array $params): string
    {
        if ([] === $params) {
            return $message;
        }

        $replacements = [];
        foreach ($params as $name => $value) {
            $replacements['{' . $name . '}'] = (string) $value;
        }

        return \strtr($message, $replacements);
    }
}
