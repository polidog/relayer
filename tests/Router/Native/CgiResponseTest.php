<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Native;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Native\CgiResponse;
use Polidog\Relayer\Router\Native\NativeProcessException;

final class CgiResponseTest extends TestCase
{
    public function testStatusHeaderIsHonoredAndRemoved(): void
    {
        $response = CgiResponse::parse("Status: 404 Not Found\r\nContent-Type: application/json\r\n\r\n{\"error\":\"nope\"}");

        self::assertSame(404, $response->status);
        self::assertSame([['Content-Type', 'application/json']], $response->headers);
        self::assertSame('{"error":"nope"}', $response->body);
        self::assertNull($response->header('Status'));
    }

    public function testMissingStatusDefaultsTo200(): void
    {
        $response = CgiResponse::parse("Content-Type: text/plain\n\nhello\n");

        self::assertSame(200, $response->status);
        self::assertSame("hello\n", $response->body);
    }

    public function testStatusWithoutReasonPhraseIsAccepted(): void
    {
        self::assertSame(204, CgiResponse::parse("Status: 204\r\n\r\n")->status);
    }

    public function testRepeatedHeadersAreKeptInOrder(): void
    {
        $response = CgiResponse::parse(
            "Set-Cookie: a=1\r\nX-One: 1\r\nSet-Cookie: b=2\r\n\r\n",
        );

        self::assertSame([
            ['Set-Cookie', 'a=1'],
            ['X-One', '1'],
            ['Set-Cookie', 'b=2'],
        ], $response->headers);
        self::assertSame('a=1', $response->header('set-cookie'));
        self::assertSame('', $response->body);
    }

    public function testHeaderLookupIsCaseInsensitive(): void
    {
        $response = CgiResponse::parse("Relayer-Next: 1\r\n\r\n");

        self::assertSame('1', $response->header('relayer-next'));
        self::assertNull($response->header('x-missing'));
    }

    public function testBodyIsPreservedByteForByteIncludingBlankLines(): void
    {
        $body = "line1\r\n\r\nline3\n\n";
        $response = CgiResponse::parse("Content-Type: text/plain\r\n\r\n" . $body);

        self::assertSame($body, $response->body);
    }

    public function testEmptyOutputIsRejected(): void
    {
        $this->expectException(NativeProcessException::class);
        $this->expectExceptionMessage('no output');
        CgiResponse::parse('');
    }

    public function testOutputWithoutHeaderTerminatorIsRejected(): void
    {
        $this->expectException(NativeProcessException::class);
        $this->expectExceptionMessage('no blank line');
        CgiResponse::parse('just some text, no headers');
    }

    public function testGarbageHeaderLineIsRejected(): void
    {
        $this->expectException(NativeProcessException::class);
        $this->expectExceptionMessage('bad header line');
        // A Go stack trace line is not a header (no colon).
        CgiResponse::parse("Content-Type: text/plain\r\ngoroutine 1 [running]\r\n\r\n");
    }

    public function testInvalidStatusValueIsRejected(): void
    {
        $this->expectException(NativeProcessException::class);
        $this->expectExceptionMessage('invalid Status');
        CgiResponse::parse("Status: teapot\r\n\r\n");
    }

    public function testHeaderNameWithSpaceIsRejected(): void
    {
        $this->expectException(NativeProcessException::class);
        $this->expectExceptionMessage('bad header name');
        CgiResponse::parse("Bad Name: x\r\n\r\n");
    }

    public function testSendWritesStatusHeadersAndBody(): void
    {
        $response = new CgiResponse(201, [['Content-Type', 'text/plain'], ['X-A', '1']], 'created');

        \ob_start();

        try {
            $response->send();
        } finally {
            $out = (string) \ob_get_clean();
        }

        self::assertSame('created', $out);
        self::assertSame(201, \http_response_code());

        if (\function_exists('xdebug_get_headers')) {
            $headers = \xdebug_get_headers();
            self::assertContains('X-A: 1', $headers);
            // PHP itself appends the default charset to a text/* type.
            self::assertNotEmpty(\preg_grep('#^Content-type: text/plain#i', $headers));
        }
    }
}
