<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\SessionStorage;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\RequestPath;
use Polidog\Relayer\I18n\LocaleResolver;

final class LocaleResolverTest extends TestCase
{
    protected function setUp(): void
    {
        // Normalize: no test should rely on a session another test left
        // active — the resolver only consults the session when one is
        // already started.
        if (\PHP_SESSION_ACTIVE === \session_status()) {
            \session_write_close();
        }
    }

    public function testPathPrefixWinsAndStripsTheSegment(): void
    {
        $resolved = $this->resolver()->resolve(
            $this->request('/ja/about', ['accept-language' => 'en'], ['locale' => 'en']),
        );

        self::assertSame('ja', $resolved->locale);
        self::assertSame('/about', $resolved->path);
        self::assertSame('path', $resolved->source);
    }

    public function testPathPrefixOnlySegmentStripsToRoot(): void
    {
        $resolved = $this->resolver()->resolve($this->request('/ja'));

        self::assertSame('ja', $resolved->locale);
        self::assertSame('/', $resolved->path);
    }

    public function testUnsupportedFirstSegmentIsNotTreatedAsLocale(): void
    {
        $resolved = $this->resolver()->resolve($this->request('/about/ja'));

        self::assertSame('en', $resolved->locale);
        self::assertSame('/about/ja', $resolved->path);
        self::assertSame('default', $resolved->source);
    }

    #[DataProvider('provideAnUnroutablePathKeepsItsPrefixCases')]
    public function testAnUnroutablePathKeepsItsPrefix(string $path): void
    {
        $resolved = $this->resolver()->resolve($this->request($path));

        self::assertSame($path, $resolved->path);
        self::assertSame('en', $resolved->locale);
        self::assertSame('default', $resolved->source);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideAnUnroutablePathKeepsItsPrefixCases(): iterable
    {
        yield 'leading double slash' => ['//ja/admin'];

        yield 'leading double slash, locale only' => ['//ja'];

        yield 'leading double slash to the defer endpoint' => ['//ja/_defer/name'];

        yield 'leading triple slash' => ['///ja/admin'];

        yield 'malformed escape behind the prefix' => ['/ja/%zz'];

        yield 'dot-dot behind the prefix' => ['/ja/%2e%2e/admin'];

        yield 'NUL behind the prefix' => ['/ja/a%00b'];
    }

    public function testAnUnroutablePathStillTakesItsLocaleFromTheCookie(): void
    {
        $resolved = $this->resolver()->resolve($this->request('//ja/admin', cookies: ['locale' => 'ja']));

        self::assertSame('ja', $resolved->locale);
        self::assertSame('//ja/admin', $resolved->path);
        self::assertSame('cookie', $resolved->source);
    }

    public function testAnUnroutablePathStillTakesItsLocaleFromTheHeader(): void
    {
        $resolved = $this->resolver()->resolve($this->request('//ja/admin', ['accept-language' => 'ja']));

        self::assertSame('ja', $resolved->locale);
        self::assertSame('//ja/admin', $resolved->path);
        self::assertSame('accept-language', $resolved->source);
    }

    public function testStrippingThePrefixNeverTurnsADoubleSlashIntoARoutablePath(): void
    {
        $resolved = $this->resolver()->resolve($this->request('/ja//admin'));

        self::assertSame('ja', $resolved->locale);
        self::assertSame('//admin', $resolved->path);
        self::assertNull(RequestPath::forMatching($resolved->path));
    }

    public function testPathPrefixDisabledFallsThrough(): void
    {
        $resolved = $this->resolver(pathPrefix: false)->resolve(
            $this->request('/ja/about', cookies: ['locale' => 'ja']),
        );

        self::assertSame('ja', $resolved->locale);
        self::assertSame('/ja/about', $resolved->path, 'path is left intact when prefix routing is off');
        self::assertSame('cookie', $resolved->source);
    }

    public function testCookieUsedWhenNoPathPrefix(): void
    {
        $resolved = $this->resolver()->resolve(
            $this->request('/about', ['accept-language' => 'en'], ['locale' => 'ja']),
        );

        self::assertSame('ja', $resolved->locale);
        self::assertSame('cookie', $resolved->source);
    }

    public function testAcceptLanguageUsedWhenNoPathOrCookie(): void
    {
        $resolved = $this->resolver()->resolve(
            $this->request('/about', ['accept-language' => 'fr;q=0.4, ja;q=0.9']),
        );

        self::assertSame('ja', $resolved->locale);
        self::assertSame('accept-language', $resolved->source);
    }

    public function testDefaultWhenNothingResolves(): void
    {
        $resolved = $this->resolver()->resolve($this->request('/about'));

        self::assertSame('en', $resolved->locale);
        self::assertSame('default', $resolved->source);
    }

    public function testResolvedLocaleIsCanonicalSpelling(): void
    {
        $resolved = $this->resolver()->resolve(
            $this->request('/about', cookies: ['locale' => 'JA-jp']),
        );

        self::assertSame('ja', $resolved->locale, 'matched on primary subtag, returned canonical');
    }

    public function testSessionIsIgnoredWhenNoSessionIsActive(): void
    {
        $session = new class implements SessionStorage {
            public function get(string $key): mixed
            {
                return 'ja';
            }

            public function set(string $key, mixed $value): void {}

            public function remove(string $key): void {}

            public function regenerateId(): void {}

            public function clear(): void {}
        };

        // The stub would return 'ja', but with no active session the
        // resolver must NOT start one just to read a locale — it falls
        // through to the default. This guards the CDN-cacheability of
        // anonymous pages.
        $resolved = $this->resolver($session)->resolve($this->request('/about'));

        self::assertSame('en', $resolved->locale);
        self::assertSame('default', $resolved->source);
    }

    public function testDelimiterOnlyInputsResolveToDefaultWithoutThrowing(): void
    {
        // Every user-controlled source feeding LocaleNegotiator::normalize()
        // — path first segment, cookie, Accept-Language — with a
        // delimiter-only value must degrade to the default, never raise a
        // TypeError (unauthenticated one-byte 500 regression guard).
        $resolver = $this->resolver();

        self::assertSame('en', $resolver->resolve($this->request('/-/about'))->locale);
        self::assertSame(
            'en',
            $resolver->resolve($this->request('/about', cookies: ['locale' => '-']))->locale,
        );
        self::assertSame(
            'en',
            $resolver->resolve($this->request('/about', ['accept-language' => '-']))->locale,
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     */
    private function request(string $path, array $headers = [], array $cookies = []): Request
    {
        return new Request(
            method: 'GET',
            path: $path,
            headers: $headers,
            cookies: $cookies,
        );
    }

    private function resolver(?SessionStorage $session = null, bool $pathPrefix = true): LocaleResolver
    {
        return new LocaleResolver(['en', 'ja'], 'en', $pathPrefix, 'locale', $session);
    }
}
