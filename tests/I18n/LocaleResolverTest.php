<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\I18n;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\SessionStorage;
use Polidog\Relayer\Http\Request;
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
