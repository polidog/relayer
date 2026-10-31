<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\TrustedProxy;

final class TrustedProxyTest extends TestCase
{
    private const array CLOUDFLARE = ['173.245.48.0/20', '2400:cb00::/32'];

    public function testCloudflarePresetResolvesWhenTheHopIsCloudflare(): void
    {
        $proxy = TrustedProxy::middleware(['preset' => 'cloudflare', 'proxies' => self::CLOUDFLARE]);

        $seen = $this->dispatch($proxy, $this->req('173.245.48.10', ['cf-connecting-ip' => '198.51.100.7']));

        self::assertSame('198.51.100.7', $seen->clientIp());
        self::assertSame('173.245.48.10', $seen->ip(), 'ip() must keep reporting REMOTE_ADDR');
    }

    public function testCloudflarePresetIgnoresTheHeaderFromAnUntrustedHop(): void
    {
        $proxy = TrustedProxy::middleware(['preset' => 'cloudflare', 'proxies' => self::CLOUDFLARE]);

        $seen = $this->dispatch($proxy, $this->req('203.0.113.5', ['cf-connecting-ip' => '198.51.100.7']));

        self::assertSame('203.0.113.5', $seen->clientIp(), 'a spoofed CF-Connecting-IP must not be honored');
        self::assertSame('203.0.113.5', $seen->ip());
    }

    public function testIpv6HopIsMatchedAgainstAnIpv6Range(): void
    {
        $proxy = TrustedProxy::middleware(['preset' => 'cloudflare', 'proxies' => self::CLOUDFLARE]);

        $seen = $this->dispatch($proxy, $this->req('2400:cb00:1234::1', ['cf-connecting-ip' => '2001:db8::9']));

        self::assertSame('2001:db8::9', $seen->clientIp());
    }

    public function testFlyPresetTrustsTheFlyProxyWithoutARangeList(): void
    {
        $proxy = TrustedProxy::middleware(['preset' => 'fly']);

        $seen = $this->dispatch($proxy, $this->req('fdaa:0:1::3', ['fly-client-ip' => '198.51.100.7']));

        self::assertSame('198.51.100.7', $seen->clientIp());
    }

    public function testFlyPresetEnforcesTheRangeListWhenGiven(): void
    {
        $proxy = TrustedProxy::middleware(['preset' => 'fly', 'proxies' => ['fdaa::/16']]);

        self::assertSame('198.51.100.7', $this->dispatch($proxy, $this->req('fdaa:0:1::3', ['fly-client-ip' => '198.51.100.7']))->clientIp());
        self::assertSame('203.0.113.5', $this->dispatch($proxy, $this->req('203.0.113.5', ['fly-client-ip' => '198.51.100.7']))->clientIp());
    }

    public function testXffWalksFromTheRightPastTrustedProxies(): void
    {
        $proxy = TrustedProxy::middleware(['preset' => 'xff', 'proxies' => ['10.0.0.0/8']]);

        // client-supplied junk, real client, two of our own proxies
        $seen = $this->dispatch($proxy, $this->req('10.0.0.2', ['x-forwarded-for' => '1.1.1.1, 198.51.100.7, 10.0.0.1']));

        self::assertSame('198.51.100.7', $seen->clientIp());
    }

    public function testXffFromAnUntrustedHopIsIgnored(): void
    {
        $proxy = TrustedProxy::middleware(['preset' => 'xff', 'proxies' => ['10.0.0.0/8']]);

        $seen = $this->dispatch($proxy, $this->req('203.0.113.5', ['x-forwarded-for' => '198.51.100.7']));

        self::assertSame('203.0.113.5', $seen->clientIp());
    }

    public function testXffWithOnlyTrustedHopsResolvesNothing(): void
    {
        $proxy = TrustedProxy::middleware(['preset' => 'xff', 'proxies' => ['10.0.0.0/8']]);

        $seen = $this->dispatch($proxy, $this->req('10.0.0.2', ['x-forwarded-for' => '10.0.0.1']));

        self::assertSame('10.0.0.2', $seen->clientIp());
    }

    public function testMalformedHeaderValueIsIgnored(): void
    {
        $proxy = TrustedProxy::middleware(['preset' => 'fly']);

        $seen = $this->dispatch($proxy, $this->req('fdaa::1', ['fly-client-ip' => 'not-an-ip']));

        self::assertSame('fdaa::1', $seen->clientIp());
    }

    public function testMissingHeaderLeavesTheRequestUntouched(): void
    {
        $proxy = TrustedProxy::middleware(['preset' => 'cloudflare', 'proxies' => self::CLOUDFLARE]);
        $request = $this->req('173.245.48.10');

        self::assertSame($request, $this->dispatch($proxy, $request));
    }

    public function testCustomHeaderWithRangeList(): void
    {
        $proxy = TrustedProxy::middleware(['header' => 'X-Real-IP', 'proxies' => ['192.0.2.1']]);

        self::assertSame('198.51.100.7', $this->dispatch($proxy, $this->req('192.0.2.1', ['x-real-ip' => '198.51.100.7']))->clientIp());
        self::assertSame('192.0.2.2', $this->dispatch($proxy, $this->req('192.0.2.2', ['x-real-ip' => '198.51.100.7']))->clientIp());
    }

    /**
     * @param list<string> $ranges
     */
    #[DataProvider('provideIpMatchesCases')]
    public function testIpMatches(string $ip, array $ranges, bool $expected): void
    {
        self::assertSame($expected, TrustedProxy::ipMatches($ip, $ranges));
    }

    /**
     * @return iterable<string, array{string, list<string>, bool}>
     */
    public static function provideIpMatchesCases(): iterable
    {
        yield 'v4 inside /20' => ['173.245.63.255', ['173.245.48.0/20'], true];

        yield 'v4 just outside /20' => ['173.245.64.0', ['173.245.48.0/20'], false];

        yield 'v4 /32 exact' => ['192.0.2.1', ['192.0.2.1/32'], true];

        yield 'v4 plain address' => ['192.0.2.1', ['192.0.2.1'], true];

        yield 'v4 /0 matches everything' => ['8.8.8.8', ['0.0.0.0/0'], true];

        yield 'v4 odd prefix /13' => ['10.7.255.255', ['10.0.0.0/13'], true];

        yield 'v4 odd prefix /13 outside' => ['10.8.0.0', ['10.0.0.0/13'], false];

        yield 'v6 inside /32' => ['2400:cb00:ffff::1', ['2400:cb00::/32'], true];

        yield 'v6 outside /32' => ['2400:cb01::1', ['2400:cb00::/32'], false];

        yield 'v6 /128 exact' => ['::1', ['::1/128'], true];

        yield 'v6 against v4 range never matches' => ['::ffff:192.0.2.1', ['192.0.2.0/24'], false];

        yield 'v4 against v6 range never matches' => ['192.0.2.1', ['::/0'], false];

        yield 'malformed ip never matches' => ['not-an-ip', ['0.0.0.0/0'], false];

        yield 'second range matches' => ['10.1.2.3', ['192.0.2.0/24', '10.0.0.0/8'], true];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[DataProvider('provideInvalidConfigIsRejectedUpFrontCases')]
    public function testInvalidConfigIsRejectedUpFront(array $config): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @var array{preset?: string, header?: string, proxies?: list<string>} $config */
        TrustedProxy::middleware($config);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function provideInvalidConfigIsRejectedUpFrontCases(): iterable
    {
        yield 'cloudflare without proxies' => [['preset' => 'cloudflare']];

        yield 'xff without proxies' => [['preset' => 'xff']];

        yield 'custom header without proxies' => [['header' => 'X-Real-IP']];

        yield 'unknown preset' => [['preset' => 'akamai', 'proxies' => ['10.0.0.0/8']]];

        yield 'neither preset nor header' => [['proxies' => ['10.0.0.0/8']]];

        yield 'bad range' => [['preset' => 'xff', 'proxies' => ['10.0.0.0/33']]];

        yield 'bad address' => [['preset' => 'xff', 'proxies' => ['ten.0.0.0/8']]];
    }

    /**
     * @param array<string, string> $headers
     */
    private function req(string $remoteAddr, array $headers = []): Request
    {
        return new Request(method: 'GET', path: '/', headers: $headers, ip: $remoteAddr);
    }

    private function dispatch(Closure $middleware, Request $request): Request
    {
        $seen = null;
        $middleware($request, static function (Request $r) use (&$seen): void {
            $seen = $r;
        });
        self::assertInstanceOf(Request::class, $seen, 'the middleware must always continue to the route');

        return $seen;
    }
}
