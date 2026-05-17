<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http\Client;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Client\HttpClientException;
use Polidog\Relayer\Http\Client\HttpResponse;
use Polidog\Relayer\Http\Client\TraceableHttpClient;
use Polidog\Relayer\Profiler\RecordingProfiler;

final class TraceableHttpClientTest extends TestCase
{
    public function testRequestRecordsSpanWithMethodUrlStatusAndSize(): void
    {
        $inner = new FakeHttpClient();
        $inner->response = new HttpResponse(201, ['Content-Type' => 'application/json'], '{"id":1}');

        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $http = new TraceableHttpClient($inner, $profiler);
        $http->request('post', 'https://api.test/users', ['Accept' => 'application/json'], '{}');

        $events = $profile->getEvents();
        self::assertCount(1, $events);
        self::assertSame('http', $events[0]->collector);
        self::assertSame('request', $events[0]->label);
        self::assertSame('POST', $events[0]->payload['method']);
        self::assertSame('https://api.test/users', $events[0]->payload['url']);
        self::assertSame(201, $events[0]->payload['status']);
        self::assertSame(8, $events[0]->payload['bytes']);
        self::assertNotNull($events[0]->durationMs);
    }

    public function testRequestHeadersAndBodyAreNotRecorded(): void
    {
        $inner = new FakeHttpClient();

        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $http = new TraceableHttpClient($inner, $profiler);
        $http->request('GET', 'https://api.test/x', ['Authorization' => 'Bearer super-secret-token']);

        $payload = $profile->getEvents()[0]->payload;
        self::assertArrayNotHasKey('headers', $payload);
        self::assertArrayNotHasKey('body', $payload);
        self::assertStringNotContainsString('super-secret-token', \json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    public function testFailedRequestIsRecordedThenRethrown(): void
    {
        $inner = new FakeHttpClient();
        $inner->throw = new HttpClientException('connection refused');

        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $http = new TraceableHttpClient($inner, $profiler);

        try {
            $http->get('https://api.test/down');
            self::fail('expected HttpClientException');
        } catch (HttpClientException $e) {
            self::assertSame('connection refused', $e->getMessage());
        }

        $events = $profile->getEvents();
        self::assertCount(1, $events);
        self::assertSame('request', $events[0]->label);
        self::assertSame('GET', $events[0]->payload['method']);
        self::assertSame('connection refused', $events[0]->payload['error']);
    }
}
