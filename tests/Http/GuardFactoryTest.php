<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http;

use Closure;
use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\GuardFactory;
use Polidog\Relayer\Http\Request;

final class GuardFactoryTest extends TestCase
{
    private const array VARS = [
        'ORIGIN_SECRET', 'ORIGIN_SECRET_PREVIOUS', 'ORIGIN_GUARD_HEADER', 'ORIGIN_GUARD_EXCEPT', 'ORIGIN_GUARD_MODE',
        'TRUSTED_PROXIES', 'TRUSTED_PROXY_PRESET', 'CLOUDFLARE_IP_RANGES',
    ];

    protected function setUp(): void
    {
        \http_response_code(200);
    }

    protected function tearDown(): void
    {
        foreach (self::VARS as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    public function testNothingConfiguredMeansNoGuard(): void
    {
        self::assertNull(GuardFactory::fromEnv());
    }

    public function testOriginSecretTurnsTheGuardOn(): void
    {
        $_ENV['ORIGIN_SECRET'] = 's3cret';
        $_ENV['ORIGIN_GUARD_EXCEPT'] = '/healthz, /_health/*';
        $log = new TestHandler();

        $guard = GuardFactory::fromEnv(new Logger('t', [$log]));
        self::assertNotNull($guard);

        \ob_start();
        $seen = $this->dispatch($guard, $this->req('/'));
        \ob_end_clean();
        self::assertNull($seen, 'without the header the route must not run');
        self::assertSame(403, \http_response_code());
        self::assertTrue($log->hasWarningRecords(), 'the DI logger must receive the report');

        self::assertNotNull($this->dispatch($guard, $this->req('/', ['x-origin-secret' => 's3cret'])));
        self::assertNotNull($this->dispatch($guard, $this->req('/_health/db')), 'ORIGIN_GUARD_EXCEPT must be honored');
    }

    public function testPreviousSecretAloneStillTurnsTheGuardOn(): void
    {
        $_ENV['ORIGIN_SECRET_PREVIOUS'] = 'old';

        $guard = GuardFactory::fromEnv();
        self::assertNotNull($guard);
        self::assertNotNull($this->dispatch($guard, $this->req('/', ['x-origin-secret' => 'old'])));
    }

    public function testCustomHeaderAndReportMode(): void
    {
        $_ENV['ORIGIN_SECRET'] = 's3cret';
        $_ENV['ORIGIN_GUARD_HEADER'] = 'X-Edge-Token';
        $_ENV['ORIGIN_GUARD_MODE'] = 'report';

        $guard = GuardFactory::fromEnv();
        self::assertNotNull($guard);

        self::assertNotNull($this->dispatch($guard, $this->req('/')), 'report mode must let the request through');
        self::assertSame(200, \http_response_code());
        self::assertNotNull($this->dispatch($guard, $this->req('/', ['x-edge-token' => 's3cret'])));
    }

    public function testTrustedProxiesTurnsIpResolutionOnWithXffByDefault(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8';

        $guard = GuardFactory::fromEnv();
        self::assertNotNull($guard);

        $seen = $this->dispatch($guard, $this->req('/', ['x-forwarded-for' => '198.51.100.7, 10.0.0.1'], '10.0.0.2'));
        self::assertNotNull($seen);
        self::assertSame('198.51.100.7', $seen->clientIp());
        self::assertSame('10.0.0.2', $seen->ip());
    }

    public function testFlyPresetWithCloudflareLayerBehindIt(): void
    {
        $_ENV['TRUSTED_PROXIES'] = 'fdaa::/16';
        $_ENV['TRUSTED_PROXY_PRESET'] = 'fly';
        $_ENV['CLOUDFLARE_IP_RANGES'] = '173.245.48.0/20,2400:cb00::/32';

        $guard = GuardFactory::fromEnv();
        self::assertNotNull($guard);

        // Via Cloudflare → Fly: the user is in CF-Connecting-IP.
        $seen = $this->dispatch($guard, $this->req('/', [
            'fly-client-ip' => '173.245.48.10',
            'cf-connecting-ip' => '198.51.100.7',
        ], 'fdaa:0:1::3'));
        self::assertNotNull($seen);
        self::assertSame('198.51.100.7', $seen->clientIp());

        // Straight to xxx.fly.dev: the caller itself; the forged CF header is ignored.
        $seen = $this->dispatch($guard, $this->req('/', [
            'fly-client-ip' => '203.0.113.5',
            'cf-connecting-ip' => '127.0.0.1',
        ], 'fdaa:0:1::3'));
        self::assertNotNull($seen);
        self::assertSame('203.0.113.5', $seen->clientIp());
    }

    public function testCloudflareRangesAreIgnoredBehindACloudflareFirstLayer(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '173.245.48.0/20';
        $_ENV['TRUSTED_PROXY_PRESET'] = 'cloudflare';
        $_ENV['CLOUDFLARE_IP_RANGES'] = '173.245.48.0/20';

        $guard = GuardFactory::fromEnv();
        self::assertNotNull($guard);

        $seen = $this->dispatch($guard, $this->req('/', ['cf-connecting-ip' => '198.51.100.7'], '173.245.48.10'));
        self::assertNotNull($seen);
        self::assertSame('198.51.100.7', $seen->clientIp());
    }

    public function testBothResolveTheClientIpBeforeTheGuardLogsARejection(): void
    {
        $_ENV['ORIGIN_SECRET'] = 's3cret';
        $_ENV['TRUSTED_PROXIES'] = 'fdaa::/16';
        $_ENV['TRUSTED_PROXY_PRESET'] = 'fly';
        $log = new TestHandler();

        $guard = GuardFactory::fromEnv(new Logger('t', [$log], [new PsrLogMessageProcessor()]));
        self::assertNotNull($guard);

        \ob_start();
        $seen = $this->dispatch($guard, $this->req('/', ['fly-client-ip' => '198.51.100.7'], 'fdaa::1'));
        \ob_end_clean();
        self::assertNull($seen, 'a request without the secret is rejected');
        // TrustedProxy sits outside the guard, so the bypass attempt is
        // logged with its real source, not the Fly proxy's 6PN address.
        self::assertTrue($log->hasWarningThatContains('from 198.51.100.7'));

        $seen = $this->dispatch($guard, $this->req('/', ['fly-client-ip' => '198.51.100.7', 'x-origin-secret' => 's3cret'], 'fdaa::1'));
        self::assertNotNull($seen);
        self::assertSame('198.51.100.7', $seen->clientIp());
    }

    public function testUnknownGuardModeFailsLoudly(): void
    {
        $_ENV['ORIGIN_SECRET'] = 's3cret';
        $_ENV['ORIGIN_GUARD_MODE'] = 'Report';

        $this->expectException(InvalidArgumentException::class);

        GuardFactory::fromEnv();
    }

    public function testUnknownPresetFailsLoudly(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '10.0.0.0/8';
        $_ENV['TRUSTED_PROXY_PRESET'] = 'akamai';

        $this->expectException(InvalidArgumentException::class);

        GuardFactory::fromEnv();
    }

    /**
     * @param array<string, string> $headers
     */
    private function req(string $path, array $headers = [], string $remoteAddr = '203.0.113.9'): Request
    {
        return new Request(method: 'GET', path: $path, headers: $headers, ip: $remoteAddr);
    }

    /**
     * The Request the route would see, or null when the guard short-circuited.
     */
    private function dispatch(Closure $guard, Request $request): ?Request
    {
        $seen = null;
        $guard($request, static function (Request $r) use (&$seen): void {
            $seen = $r;
        });

        return $seen;
    }
}
