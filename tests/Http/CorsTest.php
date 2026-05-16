<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http;

use Closure;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Cors;
use Polidog\Relayer\Http\Request;

final class CorsTest extends TestCase
{
    protected function setUp(): void
    {
        \http_response_code(200);
    }

    public function testNoOriginJustContinues(): void
    {
        [$next, $wasCalled] = $this->next();
        $cors = Cors::middleware(['origins' => ['*']]);

        $cors($this->req('GET'), $next);

        self::assertTrue($wasCalled(), 'a non-CORS request must continue to the route');
    }

    public function testSpecificAllowedOriginContinues(): void
    {
        [$next, $wasCalled] = $this->next();
        $cors = Cors::middleware(['origins' => ['https://app.example.com']]);

        $cors($this->req('GET', ['origin' => 'https://app.example.com']), $next);

        self::assertTrue($wasCalled());
        if (\function_exists('xdebug_get_headers')) {
            self::assertContains('Access-Control-Allow-Origin: https://app.example.com', \xdebug_get_headers());
        }
    }

    public function testDisallowedOriginStillContinuesWithoutHeader(): void
    {
        [$next, $wasCalled] = $this->next();
        $cors = Cors::middleware(['origins' => ['https://app.example.com']]);

        $cors($this->req('GET', ['origin' => 'https://evil.example.com']), $next);

        // Same-origin / no-CORS clients must not be broken; the browser
        // simply won't see an allow header for the disallowed origin.
        self::assertTrue($wasCalled());
    }

    public function testPreflightShortCircuitsWith204(): void
    {
        [$next, $wasCalled] = $this->next();
        $cors = Cors::middleware(['origins' => ['*']]);

        $cors(
            $this->req('OPTIONS', [
                'origin' => 'https://app.example.com',
                'access-control-request-method' => 'POST',
            ]),
            $next,
        );

        self::assertFalse($wasCalled(), 'preflight must NOT reach the route');
        self::assertSame(204, \http_response_code());
    }

    public function testOptionsWithoutPreflightHeaderIsTreatedAsNormalRequest(): void
    {
        [$next, $wasCalled] = $this->next();
        $cors = Cors::middleware(['origins' => ['*']]);

        $cors($this->req('OPTIONS', ['origin' => 'https://app.example.com']), $next);

        self::assertTrue($wasCalled());
        self::assertNotSame(204, \http_response_code());
    }

    public function testWildcardWithCredentialsReflectsOrigin(): void
    {
        [$next, $wasCalled] = $this->next();
        $cors = Cors::middleware(['origins' => ['*'], 'credentials' => true]);

        $cors($this->req('GET', ['origin' => 'https://app.example.com']), $next);

        self::assertTrue($wasCalled());
        if (\function_exists('xdebug_get_headers')) {
            $headers = \xdebug_get_headers();
            // `*` is invalid with credentials — must echo the concrete origin.
            self::assertContains('Access-Control-Allow-Origin: https://app.example.com', $headers);
            self::assertContains('Access-Control-Allow-Credentials: true', $headers);
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private function req(string $method, array $headers = []): Request
    {
        return new Request(method: $method, path: '/api/x', headers: $headers);
    }

    /**
     * @return array{0: Closure, 1: callable(): bool}
     */
    private function next(): array
    {
        $called = false;
        $next = static function (Request $r) use (&$called): void {
            $called = true;
        };
        $wasCalled = static function () use (&$called): bool {
            return $called;
        };

        return [$next, $wasCalled];
    }
}
