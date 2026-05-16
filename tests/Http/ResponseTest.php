<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Response;
use RuntimeException;

final class ResponseTest extends TestCase
{
    protected function setUp(): void
    {
        // http_response_code() is process-global in the CLI SAPI; reset it
        // so each case starts from the default 200.
        \http_response_code(200);
    }

    public function testJsonEncodesBodySetsStatusAndContentType(): void
    {
        $output = $this->send(Response::json(['ok' => true, 'n' => 3]));

        self::assertSame('{"ok":true,"n":3}', $output);
        self::assertSame(200, \http_response_code());
        $this->assertHeaderSent('Content-Type: application/json; charset=utf-8');
    }

    public function testJsonLeavesSlashesAndUnicodeUnescaped(): void
    {
        $output = $this->send(Response::json(['msg' => 'こんにちは', 'path' => '/api/users']));

        self::assertSame('{"msg":"こんにちは","path":"/api/users"}', $output);
    }

    public function testJsonHonoursExplicitStatus(): void
    {
        $output = $this->send(Response::json(['error' => 'not found'], 404));

        self::assertSame('{"error":"not found"}', $output);
        self::assertSame(404, \http_response_code());
    }

    public function testJsonContentTypeCanBeOverriddenCaseInsensitively(): void
    {
        $response = Response::json(['a' => 1], 200, ['content-type' => 'application/problem+json']);

        // The default is replaced, not duplicated — assert on the value
        // object (xdebug_get_headers is cumulative across the process, so a
        // negative header assertion there would be flaky).
        self::assertSame(['content-type' => 'application/problem+json'], $response->headers);

        $this->send($response);
        $this->assertHeaderSent('content-type: application/problem+json');
    }

    public function testUnencodableValueRaisesRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not be JSON-encoded');

        Response::json(\INF);
    }

    public function testTextSetsPlainContentType(): void
    {
        $output = $this->send(Response::text('hello'));

        self::assertSame('hello', $output);
        self::assertSame(200, \http_response_code());
        $this->assertHeaderSent('Content-Type: text/plain; charset=utf-8');
    }

    public function testNoContentHasNoBodyAndDefaultsTo204(): void
    {
        $output = $this->send(Response::noContent());

        self::assertSame('', $output);
        self::assertSame(204, \http_response_code());
        self::assertSame([], Response::noContent()->headers);
    }

    public function testRedirectSetsLocationAndNoBody(): void
    {
        $output = $this->send(Response::redirect('/login'));

        self::assertSame('', $output);
        self::assertSame(302, \http_response_code());
        $this->assertHeaderSent('Location: /login');
    }

    public function testMakeEmitsRawBodyWithNoImplicitContentType(): void
    {
        $output = $this->send(Response::make('a,b,c', 201, ['Content-Type' => 'text/csv']));

        self::assertSame('a,b,c', $output);
        self::assertSame(201, \http_response_code());
        $this->assertHeaderSent('Content-Type: text/csv');
    }

    public function testWithoutBodyKeepsStatusAndHeadersDropsBody(): void
    {
        $output = $this->send(Response::json(['x' => 1], 201)->withoutBody());

        self::assertSame('', $output);
        self::assertSame(201, \http_response_code());
        $this->assertHeaderSent('Content-Type: application/json; charset=utf-8');
    }

    public function testWithHeaderIsImmutableAndOverridesCaseInsensitively(): void
    {
        $base = Response::noContent();
        $with = $base->withHeader('Allow', 'GET, POST');

        self::assertNotSame($base, $with);
        self::assertSame([], $base->headers);
        self::assertSame(['Allow' => 'GET, POST'], $with->headers);

        $replaced = $with->withHeader('allow', 'GET');
        self::assertSame(['allow' => 'GET'], $replaced->headers);
    }

    private function send(Response $response): string
    {
        \ob_start();

        try {
            $response->send();
        } finally {
            $output = (string) \ob_get_clean();
        }

        return $output;
    }

    private function assertHeaderSent(string $header): void
    {
        if (!\function_exists('xdebug_get_headers')) {
            return;
        }

        self::assertContains($header, \xdebug_get_headers());
    }
}
