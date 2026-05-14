<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Http\CachePolicy;
use Polidog\Relayer\Tests\Fixtures\CachedPage;
use Polidog\Relayer\Tests\Fixtures\DynamicEtagPage;
use stdClass;

final class CachePolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        unset(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['HTTP_IF_NONE_MATCH'],
            $_SERVER['HTTP_IF_MODIFIED_SINCE'],
        );
    }

    public function testBuildDirectivesEmitsAllFlags(): void
    {
        $cache = new Cache(
            maxAge: 60,
            sMaxAge: 600,
            public: true,
            noCache: true,
            mustRevalidate: true,
            immutable: true,
        );

        self::assertSame(
            ['public', 'no-cache', 'max-age=60', 's-maxage=600', 'must-revalidate', 'immutable'],
            CachePolicy::buildDirectives($cache),
        );
    }

    public function testPrivateWinsOnlyWhenPublicNotSet(): void
    {
        self::assertSame(
            ['private', 'max-age=10'],
            CachePolicy::buildDirectives(new Cache(maxAge: 10, private: true)),
        );

        self::assertSame(
            ['public', 'max-age=10'],
            CachePolicy::buildDirectives(new Cache(maxAge: 10, public: true, private: true)),
        );
    }

    public function testNoStoreWithoutMaxAge(): void
    {
        self::assertSame(
            ['no-store'],
            CachePolicy::buildDirectives(new Cache(noStore: true)),
        );
    }

    public function testEmptyCacheProducesNoDirectives(): void
    {
        self::assertSame([], CachePolicy::buildDirectives(new Cache()));
    }

    public function testExtractReturnsNullForClassWithoutAttribute(): void
    {
        self::assertNull(CachePolicy::extract(stdClass::class));
    }

    public function testExtractReturnsNullForUnknownClass(): void
    {
        self::assertNull(CachePolicy::extract('Nope\Missing'));
    }

    public function testExtractReturnsAttributeInstance(): void
    {
        $cache = CachePolicy::extract(CachedPage::class);

        self::assertInstanceOf(Cache::class, $cache);
        self::assertSame(3600, $cache->maxAge);
        self::assertTrue($cache->public);
        self::assertSame('home-v1', $cache->etag);
    }

    public function testFormatEtagQuotesUnquotedValue(): void
    {
        self::assertSame('"abc"', CachePolicy::formatEtag('abc'));
    }

    public function testFormatEtagPreservesAlreadyQuotedValue(): void
    {
        self::assertSame('"abc"', CachePolicy::formatEtag('"abc"'));
    }

    public function testFormatEtagWeakPrefix(): void
    {
        self::assertSame('W/"abc"', CachePolicy::formatEtag('abc', weak: true));
        self::assertSame('W/"abc"', CachePolicy::formatEtag('"abc"', weak: true));
        self::assertSame('W/"abc"', CachePolicy::formatEtag('W/"abc"', weak: true));
    }

    public function testIsNotModifiedReturnsFalseWithoutConditionalHeaders(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        self::assertFalse(CachePolicy::isNotModified(new Cache(etag: 'v1')));
    }

    public function testIsNotModifiedMatchesStrongEtag(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_IF_NONE_MATCH'] = '"v1"';

        self::assertTrue(CachePolicy::isNotModified(new Cache(etag: 'v1')));
    }

    public function testIsNotModifiedMatchesWeakAgainstStrongPerRfc7232(): void
    {
        // Weak comparison per RFC 7232 §2.3.2: W/"v1" matches "v1".
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_IF_NONE_MATCH'] = 'W/"v1"';

        self::assertTrue(CachePolicy::isNotModified(new Cache(etag: 'v1')));
    }

    public function testIsNotModifiedMatchesAnyInList(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_IF_NONE_MATCH'] = '"old", W/"v1", "v2"';

        self::assertTrue(CachePolicy::isNotModified(new Cache(etag: 'v1')));
    }

    public function testIsNotModifiedWildcardAlwaysMatches(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_IF_NONE_MATCH'] = '*';

        self::assertTrue(CachePolicy::isNotModified(new Cache(etag: 'anything')));
    }

    public function testIsNotModifiedDoesNotMatchDifferentEtag(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_IF_NONE_MATCH'] = '"other"';

        self::assertFalse(CachePolicy::isNotModified(new Cache(etag: 'v1')));
    }

    public function testIsNotModifiedRespectsIfModifiedSince(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_IF_MODIFIED_SINCE'] = 'Wed, 01 Jan 2025 00:00:00 GMT';

        self::assertTrue(
            CachePolicy::isNotModified(new Cache(lastModified: '2024-06-01 00:00:00 GMT')),
        );

        self::assertFalse(
            CachePolicy::isNotModified(new Cache(lastModified: '2025-06-01 00:00:00 GMT')),
        );
    }

    public function testIsNotModifiedIgnoredForUnsafeMethods(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_IF_NONE_MATCH'] = '"v1"';

        self::assertFalse(CachePolicy::isNotModified(new Cache(etag: 'v1')));
    }

    public function testResolveWithStoreReturnsCacheUnchangedWhenStaticEtagSet(): void
    {
        $cache = new Cache(etag: 'static-v1', etagKey: 'home');
        $store = new InMemoryEtagStore(['home' => 'dynamic-v1']);

        $resolved = CachePolicy::resolveWithStore($cache, $store);

        self::assertSame($cache, $resolved);
        self::assertSame('static-v1', $resolved->etag);
    }

    public function testResolveWithStoreSubstitutesDynamicEtagFromStore(): void
    {
        $cache = new Cache(maxAge: 60, public: true, etagKey: 'home');
        $store = new InMemoryEtagStore(['home' => 'dynamic-v1']);

        $resolved = CachePolicy::resolveWithStore($cache, $store);

        self::assertNotSame($cache, $resolved);
        self::assertSame('dynamic-v1', $resolved->etag);
        // Other fields preserved
        self::assertSame(60, $resolved->maxAge);
        self::assertTrue($resolved->public);
        self::assertSame('home', $resolved->etagKey);
    }

    public function testResolveWithStoreReturnsOriginalWhenKeyMissing(): void
    {
        $cache = new Cache(etagKey: 'unknown');
        $store = new InMemoryEtagStore([]);

        $resolved = CachePolicy::resolveWithStore($cache, $store);

        self::assertSame($cache, $resolved);
        self::assertNull($resolved->etag);
    }

    public function testResolveWithStoreReturnsOriginalWhenStoreNull(): void
    {
        $cache = new Cache(etagKey: 'home');

        $resolved = CachePolicy::resolveWithStore($cache, null);

        self::assertSame($cache, $resolved);
    }

    public function testApplyFromAttributePullsDynamicEtagViaStore(): void
    {
        $store = new InMemoryEtagStore([
            'cached-page-key' => 'dynamic-from-store',
        ]);

        $effective = CachePolicy::applyFromAttribute(
            DynamicEtagPage::class,
            $store,
        );

        self::assertNotNull($effective);
        self::assertSame('dynamic-from-store', $effective->etag);
    }

    public function testApplyCacheReturnsCacheUnchangedWhenNoStoreOrKey(): void
    {
        $cache = new Cache(maxAge: 60, etag: 'static-v1');

        $effective = CachePolicy::applyCache($cache);

        self::assertSame($cache, $effective);
    }

    public function testApplyCacheSubstitutesDynamicEtagFromStore(): void
    {
        $cache = new Cache(maxAge: 60, public: true, etagKey: 'feed');
        $store = new InMemoryEtagStore(['feed' => 'rev-42']);

        $effective = CachePolicy::applyCache($cache, $store);

        self::assertNotSame($cache, $effective);
        self::assertSame('rev-42', $effective->etag);
        // Original fields preserved
        self::assertSame(60, $effective->maxAge);
        self::assertTrue($effective->public);
    }

    public function testApplyCacheKeepsStaticEtagWhenBothAreSet(): void
    {
        $cache = new Cache(etag: 'static-wins', etagKey: 'feed');
        $store = new InMemoryEtagStore(['feed' => 'dynamic-loses']);

        $effective = CachePolicy::applyCache($cache, $store);

        self::assertSame($cache, $effective);
        self::assertSame('static-wins', $effective->etag);
    }

    public function testApplyCacheReturnsOriginalWhenStoreHasNoEntry(): void
    {
        $cache = new Cache(maxAge: 30, etagKey: 'missing');
        $store = new InMemoryEtagStore([]);

        $effective = CachePolicy::applyCache($cache, $store);

        self::assertSame($cache, $effective);
        self::assertNull($effective->etag);
    }
}
