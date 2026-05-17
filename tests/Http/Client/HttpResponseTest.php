<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http\Client;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Client\HttpClientException;
use Polidog\Relayer\Http\Client\HttpResponse;

final class HttpResponseTest extends TestCase
{
    public function testOkIsTrueForTwoHundredRangeOnly(): void
    {
        self::assertTrue((new HttpResponse(200, [], ''))->ok());
        self::assertTrue((new HttpResponse(204, [], ''))->ok());
        self::assertTrue((new HttpResponse(299, [], ''))->ok());
        self::assertFalse((new HttpResponse(301, [], ''))->ok());
        self::assertFalse((new HttpResponse(404, [], ''))->ok());
        self::assertFalse((new HttpResponse(500, [], ''))->ok());
    }

    public function testJsonDecodesObjectsAsAssociativeArrays(): void
    {
        $res = new HttpResponse(200, [], '{"a":1,"b":["x","y"]}');

        self::assertSame(['a' => 1, 'b' => ['x', 'y']], $res->json());
    }

    public function testJsonOnInvalidBodyThrowsHttpClientException(): void
    {
        $res = new HttpResponse(200, [], '<html>not json</html>');

        $this->expectException(HttpClientException::class);
        $res->json();
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $res = new HttpResponse(200, ['Content-Type' => 'application/json'], '');

        self::assertSame('application/json', $res->header('content-type'));
        self::assertSame('application/json', $res->header('CONTENT-TYPE'));
        self::assertNull($res->header('X-Missing'));
    }
}
