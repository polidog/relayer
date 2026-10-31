<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\AuthGuard;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\I18n\LocaleResolver;

final class AuthGuardRedirectTest extends TestCase
{
    #[DataProvider('provideRedirectLocationCases')]
    public function testRedirectLocation(string $target, string $uri, string $path, string $expected): void
    {
        $locales = new LocaleResolver(['en', 'ja'], 'en');
        $request = new Request('GET', $path, uri: $uri);

        self::assertSame($expected, AuthGuard::redirectLocation($target, $request, $locales));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function provideRedirectLocationCases(): iterable
    {
        yield 'query is kept' => ['/login', '/boards/1?x=1&y=2', '/boards/1', '/login?next=%2Fboards%2F1%3Fx%3D1%26y%3D2'];

        yield 'locale prefix is kept on both' => ['/login', '/ja/boards', '/boards', '/ja/login?next=%2Fja%2Fboards'];

        yield 'locale prefix and query' => ['/login', '/ja/boards?x=1', '/boards', '/ja/login?next=%2Fja%2Fboards%3Fx%3D1'];

        yield 'bare locale root' => ['/login', '/ja', '/', '/ja/login?next=%2Fja'];

        yield 'non-canonical prefix uses the canonical locale for the login page' => ['/login', '/JA/boards', '/boards', '/ja/login?next=%2FJA%2Fboards'];

        yield 'absolute target is not prefixed' => ['https://auth.example.com/login', '/ja/boards', '/boards', 'https://auth.example.com/login?next=%2Fja%2Fboards'];

        yield 'protocol-relative target is not prefixed' => ['//auth.example.com/login', '/ja/boards', '/boards', '//auth.example.com/login?next=%2Fja%2Fboards'];

        yield 'target already under this locale' => ['/ja/login', '/ja/boards', '/boards', '/ja/login?next=%2Fja%2Fboards'];

        yield 'target already under another locale' => ['/en/login', '/ja/boards', '/boards', '/en/login?next=%2Fja%2Fboards'];

        yield 'target query suppresses next but not the prefix' => ['/login?from=app', '/ja/boards', '/boards', '/ja/login?from=app'];

        yield 'first segment that is not a locale' => ['/login', '/jazz/boards', '/jazz/boards', '/login?next=%2Fjazz%2Fboards'];

        yield 'crafted request line never reaches next' => ['/login', '//evil.com/boards?x=1', '/boards', '/login?next=%2Fboards'];

        yield 'absolute-form request line never reaches next' => ['/login', 'http://evil.com/boards', '/boards', '/login?next=%2Fboards'];

        yield 'backslash request line never reaches next' => ['/login', '/\evil.com', '/\evil.com', '/login'];
    }

    public function testWithoutLocaleResolverAPrefixLookingSegmentIsAnOrdinaryPath(): void
    {
        $request = new Request('GET', '/ja/boards', uri: '/ja/boards?x=1');

        self::assertSame('/login?next=%2Fja%2Fboards%3Fx%3D1', AuthGuard::redirectLocation('/login', $request));
    }

    public function testPathPrefixDisabledLeavesTheLoginTargetAlone(): void
    {
        $locales = new LocaleResolver(['en', 'ja'], 'en', pathPrefix: false);
        $request = new Request('GET', '/ja/boards', uri: '/ja/boards');

        self::assertSame('/login?next=%2Fja%2Fboards', AuthGuard::redirectLocation('/login', $request, $locales));
    }

    public function testWithoutARequestTheTargetIsUnchanged(): void
    {
        self::assertSame('/login', AuthGuard::redirectLocation('/login', null));
    }

    #[DataProvider('provideSafeNextAcceptsSameOriginPathsCases')]
    public function testSafeNextAcceptsSameOriginPaths(string $next): void
    {
        self::assertSame($next, AuthGuard::safeNext($next, '/fallback'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSafeNextAcceptsSameOriginPathsCases(): iterable
    {
        yield 'path' => ['/boards/1'];

        yield 'root' => ['/'];

        yield 'path with query' => ['/boards/1?x=1&y=2'];

        yield 'locale prefix with query' => ['/ja/boards?x=1'];

        yield 'query holding a url' => ['/search?u=https://example.com//x'];

        yield 'double slash later in the path' => ['/a//b'];

        yield 'encoded slashes stay in the path' => ['/%2F%2Fevil.com'];
    }

    #[DataProvider('provideSafeNextRejectsAnythingThatCouldLeaveTheOriginCases')]
    public function testSafeNextRejectsAnythingThatCouldLeaveTheOrigin(?string $next): void
    {
        self::assertSame('/fallback', AuthGuard::safeNext($next, '/fallback'));
    }

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function provideSafeNextRejectsAnythingThatCouldLeaveTheOriginCases(): iterable
    {
        yield 'missing' => [null];

        yield 'empty' => [''];

        yield 'protocol-relative' => ['//evil.com'];

        yield 'triple slash' => ['///evil.com'];

        yield 'slash backslash' => ['/\evil.com'];

        yield 'slash backslash slash' => ['/\/evil.com'];

        yield 'double backslash' => ['\\\evil.com'];

        yield 'backslash slash' => ['\/evil.com'];

        yield 'https scheme' => ['https://evil.com/'];

        yield 'mixed case scheme' => ['HtTp://evil.com/'];

        yield 'scheme without slashes' => ['https:evil.com'];

        yield 'javascript scheme' => ['javascript:alert(1)'];

        yield 'data scheme' => ['data:text/html,x'];

        yield 'relative path' => ['boards/1'];

        yield 'leading space' => [' /boards'];

        yield 'tab smuggled slash' => ["/\t/evil.com"];

        yield 'newline smuggled slash' => ["/\n/evil.com"];

        yield 'carriage return smuggled slash' => ["/\r/evil.com"];

        yield 'response splitting' => ["/x\r\nSet-Cookie: a=b"];

        yield 'null byte' => ["/x\0"];

        yield 'trailing newline' => ["/x\n"];
    }
}
