<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\I18n;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\I18n\Translator;

final class TranslatorTest extends TestCase
{
    public function testDefaultLocaleIsActiveUntilChanged(): void
    {
        $t = $this->translator();
        self::assertSame('en', $t->getLocale());
        self::assertSame('Hello, Ada!', $t->trans('greeting', ['name' => 'Ada']));
    }

    public function testSetLocaleSwitchesActiveCatalog(): void
    {
        $t = $this->translator();
        $t->setLocale('ja');
        self::assertSame('ja', $t->getLocale());
        self::assertSame('こんにちは、Adaさん！', $t->trans('greeting', ['name' => 'Ada']));
    }

    public function testPerCallLocaleOverridesActive(): void
    {
        $t = $this->translator();
        self::assertSame('こんにちは、Adaさん！', $t->trans('greeting', ['name' => 'Ada'], 'ja'));
        self::assertSame('en', $t->getLocale(), 'per-call locale must not mutate state');
    }

    public function testMissingKeyReturnsKeyItself(): void
    {
        self::assertSame('no.such.key', $this->translator()->trans('no.such.key'));
    }

    public function testFallsBackToFallbackLocaleForMissingKey(): void
    {
        $t = $this->translator();
        $t->setLocale('ja');
        // 'only_en' is absent from ja → falls back to en.
        self::assertSame('English only', $t->trans('only_en'));
    }

    public function testNormalizedPrimarySubtagIsTried(): void
    {
        $t = $this->translator();
        // ja-JP is not a catalog key; the primary subtag ja is.
        self::assertSame('こんにちは、{name}さん！', $t->trans('greeting', [], 'ja-JP'));
    }

    public function testTransChoiceEnglishOneVsOther(): void
    {
        $t = $this->translator();
        self::assertSame('1 apple', $t->transChoice('apples', 1));
        self::assertSame('3 apples', $t->transChoice('apples', 3));
    }

    public function testTransChoiceJapaneseSingleFormClampsIndex(): void
    {
        $t = $this->translator();
        $t->setLocale('ja');
        self::assertSame('りんご1個', $t->transChoice('apples', 1));
        self::assertSame('りんご5個', $t->transChoice('apples', 5));
    }

    public function testHasReportsKeyPresenceWithFallback(): void
    {
        $t = $this->translator();
        $t->setLocale('ja');
        self::assertTrue($t->has('greeting'));
        self::assertTrue($t->has('only_en'), 'present via fallback locale');
        self::assertFalse($t->has('missing'));
    }

    public function testFrameworkCatalogCarriesValidationAndHttpKeys(): void
    {
        $t = Translator::framework();
        self::assertSame('Required.', $t->trans('relayer.validation.required'));
        self::assertSame('Not Found', $t->trans('relayer.http.404'));

        $t->setLocale('ja');
        self::assertSame('入力してください。', $t->trans('relayer.validation.required'));
        self::assertSame('ページが見つかりません', $t->trans('relayer.http.404'));
    }

    private function translator(): Translator
    {
        return new Translator(
            [
                'en' => [
                    'greeting' => 'Hello, {name}!',
                    'apples' => '{count} apple|{count} apples',
                    'only_en' => 'English only',
                ],
                'ja' => [
                    'greeting' => 'こんにちは、{name}さん！',
                    'apples' => 'りんご{count}個',
                ],
            ],
            'en',
            'en',
        );
    }
}
