<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Http\CachePolicy;
use Polidog\Relayer\Http\SessionStarter;

/**
 * A FrankenPHP worker keeps ini changes between requests, so what one request
 * configures must not carry over to the next. Each test simulates that by
 * running two "requests" in the one process.
 */
final class SessionWorkerStateTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testAPolicyDoesNotDisableTheLimiterForTheNextRequest(): void
    {
        \session_cache_limiter('nocache');

        CachePolicy::emit(new Cache(maxAge: 60, public: true));
        self::assertSame('', \session_cache_limiter());

        CachePolicy::restoreSessionCacheLimiter();
        self::assertSame('nocache', \session_cache_limiter());
    }

    #[RunInSeparateProcess]
    public function testTheLimiterTheAppChoseIsWhatComesBack(): void
    {
        \session_cache_limiter('private');

        CachePolicy::emit(new Cache(maxAge: 60, public: true));
        CachePolicy::emit(new Cache(maxAge: 60, public: true));
        CachePolicy::restoreSessionCacheLimiter();

        self::assertSame('private', \session_cache_limiter());
    }

    #[RunInSeparateProcess]
    public function testSecureFromAnHttpsRequestDoesNotStickToTheNextHttpRequest(): void
    {
        $_SERVER['HTTPS'] = 'on';
        SessionStarter::applyCookieDefaults();
        self::assertTrue(\session_get_cookie_params()['secure']);

        unset($_SERVER['HTTPS']);
        SessionStarter::applyCookieDefaults();
        self::assertFalse(\session_get_cookie_params()['secure']);
    }

    #[RunInSeparateProcess]
    public function testSecureTheAppSetItselfIsNotClearedOverHttp(): void
    {
        \session_set_cookie_params(['secure' => true]);

        SessionStarter::applyCookieDefaults();

        self::assertTrue(\session_get_cookie_params()['secure']);
    }
}
