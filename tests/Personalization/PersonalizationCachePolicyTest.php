<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Personalization;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Http\CachePolicy;
use Polidog\Relayer\Personalization\PersonalizationCachePolicy;

final class PersonalizationCachePolicyTest extends TestCase
{
    public function testDefaultCacheIsPrivateNoStoreWithVaryCookie(): void
    {
        $cache = PersonalizationCachePolicy::defaultCache();

        self::assertTrue($cache->private);
        self::assertTrue($cache->noStore);
        self::assertFalse($cache->public);
        self::assertNull($cache->sMaxAge);
        self::assertSame(['Cookie'], $cache->vary);
        self::assertSame(['private', 'no-store'], CachePolicy::buildDirectives($cache));
    }

    public function testAssertSafeRejectsPublic(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PersonalizationCachePolicy::assertSafe(new Cache(public: true));
    }

    public function testAssertSafeRejectsSMaxAge(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PersonalizationCachePolicy::assertSafe(new Cache(private: true, sMaxAge: 60));
    }

    public function testAssertSafeAcceptsPrivateMaxAge(): void
    {
        $this->expectNotToPerformAssertions();
        // private + maxAge is the safe opt-in for short browser caching.
        PersonalizationCachePolicy::assertSafe(new Cache(private: true, maxAge: 60));
    }

    public function testAssertSafeAcceptsDefaultCache(): void
    {
        $this->expectNotToPerformAssertions();
        PersonalizationCachePolicy::assertSafe(PersonalizationCachePolicy::defaultCache());
    }

    public function testApplyWithUserPublicCacheThrowsBeforeEmitting(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PersonalizationCachePolicy::apply(new Cache(public: true, maxAge: 60));
    }
}
