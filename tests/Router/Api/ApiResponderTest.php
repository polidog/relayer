<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Api;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Api\ApiResponder;
use RuntimeException;

final class ApiResponderTest extends TestCase
{
    protected function setUp(): void
    {
        // http_response_code() is process-global state in the CLI SAPI;
        // reset it so each case starts from the default 200.
        \http_response_code(200);
    }

    public function testArrayIsJsonEncodedWithStatus200(): void
    {
        $output = $this->capture(static fn () => ApiResponder::emit(['ok' => true, 'n' => 3]));

        self::assertSame('{"ok":true,"n":3}', $output);
        self::assertSame(200, \http_response_code());
        $this->assertContentTypeJson();
    }

    public function testNullProducesNoBodyAnd204(): void
    {
        $output = $this->capture(static fn () => ApiResponder::emit(null));

        self::assertSame('', $output);
        self::assertSame(204, \http_response_code());
    }

    public function testNullKeepsHandlerChosenStatus(): void
    {
        \http_response_code(202);

        $output = $this->capture(static fn () => ApiResponder::emit(null));

        self::assertSame('', $output);
        self::assertSame(202, \http_response_code(), 'a handler-set status must win over the 204 default');
    }

    public function testHandlerSetStatusPassesThroughWithBody(): void
    {
        \http_response_code(404);

        $output = $this->capture(static fn () => ApiResponder::emit(['error' => 'not found']));

        self::assertSame('{"error":"not found"}', $output);
        self::assertSame(404, \http_response_code());
    }

    public function testUnicodeAndSlashesAreLeftUnescaped(): void
    {
        $output = $this->capture(
            static fn () => ApiResponder::emit(['msg' => 'こんにちは', 'path' => '/api/users']),
        );

        self::assertSame('{"msg":"こんにちは","path":"/api/users"}', $output);
    }

    public function testUnencodableValueRaisesRuntimeException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not be JSON-encoded');

        $this->capture(static fn () => ApiResponder::emit(\INF));
    }

    private function capture(callable $fn): string
    {
        \ob_start();

        try {
            $fn();
        } finally {
            $output = (string) \ob_get_clean();
        }

        return $output;
    }

    private function assertContentTypeJson(): void
    {
        if (!\function_exists('xdebug_get_headers')) {
            return;
        }

        self::assertContains('Content-Type: application/json; charset=utf-8', \xdebug_get_headers());
    }
}
