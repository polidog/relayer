<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http\Client;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Client\CachingHttpClient;
use Polidog\Relayer\Http\Client\HttpResponse;
use Polidog\Relayer\Profiler\RecordingProfiler;
use RuntimeException;

final class CachingHttpClientTest extends TestCase
{
    public function testIdenticalGetHitsCacheAndRecordsHit(): void
    {
        $inner = new FakeHttpClient();
        $inner->response = new HttpResponse(200, [], 'body');

        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $http = new CachingHttpClient($inner, $profiler);

        $first = $http->get('https://api.test/users');
        $second = $http->get('https://api.test/users');

        self::assertSame($first, $second);
        self::assertSame(1, $inner->requestCalls, 'inner fetched only once');

        $events = $profile->getEvents();
        self::assertCount(1, $events);
        self::assertSame('http', $events[0]->collector);
        self::assertSame('cache_hit', $events[0]->label);
        self::assertSame(['method' => 'GET', 'url' => 'https://api.test/users'], $events[0]->payload);
    }

    public function testDifferentHeadersAreNotShared(): void
    {
        $inner = new FakeHttpClient();
        $http = new CachingHttpClient($inner, new RecordingProfiler());

        $http->get('https://api.test/x', ['Accept' => 'application/json']);
        $http->get('https://api.test/x', ['Accept' => 'text/csv']);

        self::assertSame(2, $inner->requestCalls);
    }

    public function testHeadIsMemoizedSeparatelyFromGet(): void
    {
        $inner = new FakeHttpClient();
        $http = new CachingHttpClient($inner, new RecordingProfiler());

        $url = 'https://api.test/x';
        $http->request('GET', $url);
        $http->request('HEAD', $url);
        $http->request('GET', $url);
        $http->request('HEAD', $url);

        self::assertSame(2, $inner->requestCalls, 'GET and HEAD cached independently, one fetch each');
    }

    public function testUnsafeMethodIsNotCachedAndFlushesEverything(): void
    {
        $inner = new FakeHttpClient();
        $http = new CachingHttpClient($inner, new RecordingProfiler());

        $http->get('https://api.test/x');           // cached
        $http->request('POST', 'https://api.test/x'); // not cached, flushes
        $http->get('https://api.test/x');           // refetched

        self::assertSame(3, $inner->requestCalls, 'POST invalidated the memoized GET');

        $http->request('POST', 'https://api.test/x');
        $http->request('POST', 'https://api.test/x');
        self::assertSame(5, $inner->requestCalls, 'every POST hits the network');
    }

    public function testCacheHitShortCircuitsBeforeInner(): void
    {
        $inner = new FakeHttpClient();
        $inner->throw = new RuntimeException('must not be called on a hit');
        $http = new CachingHttpClient($inner, new RecordingProfiler());

        try {
            $http->get('https://api.test/x');
            self::fail('first call should reach the throwing inner');
        } catch (RuntimeException) {
        }

        // First call threw before caching, so this still reaches inner.
        $inner->throw = null;
        $inner->response = new HttpResponse(200, [], 'ok');
        $a = $http->get('https://api.test/x');
        $inner->throw = new RuntimeException('cache hit must not touch inner');
        $b = $http->get('https://api.test/x');

        self::assertSame($a, $b);
    }
}
