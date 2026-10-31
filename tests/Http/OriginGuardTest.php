<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http;

use Closure;
use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\OriginGuard;
use Polidog\Relayer\Http\Request;

final class OriginGuardTest extends TestCase
{
    private TestHandler $log;

    protected function setUp(): void
    {
        \http_response_code(200);
        $this->log = new TestHandler();
    }

    public function testMatchingSecretContinues(): void
    {
        [$next, $wasCalled] = $this->next();
        $guard = $this->guard(['secrets' => ['s3cret']]);

        $guard($this->req(['x-origin-secret' => 's3cret']), $next);

        self::assertTrue($wasCalled());
        self::assertSame(200, \http_response_code());
        self::assertFalse($this->log->hasWarningRecords());
    }

    public function testMismatchIsRejectedWith403AndRouteNeverRuns(): void
    {
        [$next, $wasCalled] = $this->next();
        $guard = $this->guard(['secrets' => ['s3cret']]);

        \ob_start();
        $guard($this->req(['x-origin-secret' => 'wrong']), $next);
        $body = (string) \ob_get_clean();

        self::assertFalse($wasCalled());
        self::assertSame(403, \http_response_code());
        self::assertSame('Forbidden', $body);
    }

    public function testMissingHeaderIsRejected(): void
    {
        [$next, $wasCalled] = $this->next();
        $guard = $this->guard(['secrets' => ['s3cret']]);

        \ob_start();
        $guard($this->req(), $next);
        \ob_end_clean();

        self::assertFalse($wasCalled());
        self::assertSame(403, \http_response_code());
        self::assertTrue($this->log->hasWarningThatContains('OriginGuard rejected GET /api/x from 203.0.113.9: origin secret missing'));
    }

    public function testRotationAcceptsOldAndNewSecret(): void
    {
        $guard = $this->guard(['secrets' => ['new', 'old']]);

        foreach (['new', 'old'] as $secret) {
            [$next, $wasCalled] = $this->next();
            $guard($this->req(['x-origin-secret' => $secret]), $next);
            self::assertTrue($wasCalled(), "secret \"{$secret}\" must be accepted during rotation");
        }

        [$next, $wasCalled] = $this->next();
        \ob_start();
        $guard($this->req(['x-origin-secret' => 'older']), $next);
        \ob_end_clean();
        self::assertFalse($wasCalled());
    }

    public function testNoSecretConfiguredDisablesTheGuard(): void
    {
        // A hand-built `secrets` list whose lookups all came back empty.
        $guard = $this->guard(['secrets' => \array_filter([$_SERVER['RELAYER_TEST_UNSET_A'] ?? null, $_SERVER['RELAYER_TEST_UNSET_B'] ?? null])]);
        [$next, $wasCalled] = $this->next();

        $guard($this->req(), $next);

        self::assertTrue($wasCalled());
        self::assertSame(200, \http_response_code());
    }

    public function testSecretsFromEnvReadsTheFrameworkEnvCascade(): void
    {
        $_ENV['RELAYER_TEST_ORIGIN_A'] = 'from-env';
        $_SERVER['RELAYER_TEST_ORIGIN_B'] = 'from-server';
        \putenv('RELAYER_TEST_ORIGIN_C=from-getenv');
        $_SERVER['RELAYER_TEST_ORIGIN_BLANK'] = '';

        try {
            $guard = $this->guard(['secretsFromEnv' => [
                'RELAYER_TEST_ORIGIN_A',
                'RELAYER_TEST_ORIGIN_B',
                'RELAYER_TEST_ORIGIN_C',
                'RELAYER_TEST_ORIGIN_BLANK',
                'RELAYER_TEST_ORIGIN_UNSET',
            ]]);

            foreach (['from-env', 'from-server', 'from-getenv'] as $secret) {
                [$next, $wasCalled] = $this->next();
                $guard($this->req(['x-origin-secret' => $secret]), $next);
                self::assertTrue($wasCalled(), "secret \"{$secret}\" must be accepted");
            }

            [$next, $wasCalled] = $this->next();
            \ob_start();
            $guard($this->req(['x-origin-secret' => '']), $next);
            \ob_end_clean();
            self::assertFalse($wasCalled(), 'a blank env var must not become an empty accepted secret');
        } finally {
            unset($_ENV['RELAYER_TEST_ORIGIN_A'], $_SERVER['RELAYER_TEST_ORIGIN_B'], $_SERVER['RELAYER_TEST_ORIGIN_BLANK']);
            \putenv('RELAYER_TEST_ORIGIN_C');
        }
    }

    public function testSecretsAreTrimmedAndBlankOnesMeanUnset(): void
    {
        // `fly secrets import < file` / a `.env.local` line with trailing
        // whitespace: the CDN sends `abc`, the env holds "abc\n".
        $_SERVER['RELAYER_TEST_ORIGIN_NL'] = "abc\n";

        try {
            $guard = $this->guard(['secretsFromEnv' => ['RELAYER_TEST_ORIGIN_NL'], 'secrets' => [' lit ']]);

            foreach (['abc', 'lit'] as $secret) {
                [$next, $wasCalled] = $this->next();
                $guard($this->req(['x-origin-secret' => $secret]), $next);
                self::assertTrue($wasCalled(), "trimmed secret \"{$secret}\" must be accepted");
            }
        } finally {
            unset($_SERVER['RELAYER_TEST_ORIGIN_NL']);
        }

        // Whitespace-only is "unset": the guard stays off rather than
        // accepting a blank header.
        $_SERVER['RELAYER_TEST_ORIGIN_WS'] = ' ';

        try {
            $guard = $this->guard(['secretsFromEnv' => ['RELAYER_TEST_ORIGIN_WS'], 'secrets' => ["\t"]]);
            [$next, $wasCalled] = $this->next();
            $guard($this->req(), $next);
            self::assertTrue($wasCalled(), 'whitespace-only secrets must leave the guard disabled');
        } finally {
            unset($_SERVER['RELAYER_TEST_ORIGIN_WS']);
        }
    }

    public function testSecretsFromEnvWithNothingSetDisablesTheGuard(): void
    {
        $guard = $this->guard(['secretsFromEnv' => ['RELAYER_TEST_ORIGIN_UNSET_1', 'RELAYER_TEST_ORIGIN_UNSET_2']]);
        [$next, $wasCalled] = $this->next();

        $guard($this->req(), $next);

        self::assertTrue($wasCalled());
    }

    public function testEmptyStringSecretsDoNotCount(): void
    {
        $guard = $this->guard(['secrets' => ['', null, false]]);
        [$next, $wasCalled] = $this->next();

        $guard($this->req(['x-origin-secret' => '']), $next);

        self::assertTrue($wasCalled(), 'an empty secret must disable the guard, not accept an empty header');
    }

    public function testExceptPathsSkipTheCheck(): void
    {
        $guard = $this->guard(['secrets' => ['s3cret'], 'except' => ['/healthz', '/_status/*']]);

        foreach (['/healthz', '/_status/db'] as $path) {
            [$next, $wasCalled] = $this->next();
            $guard($this->req([], $path), $next);
            self::assertTrue($wasCalled(), "{$path} must bypass the guard");
        }

        foreach (['/healthz/deep', '/_statusx'] as $path) {
            [$next, $wasCalled] = $this->next();
            \ob_start();
            $guard($this->req([], $path), $next);
            \ob_end_clean();
            self::assertFalse($wasCalled(), "{$path} must NOT bypass the guard");
        }
    }

    public function testReportModeLogsButContinues(): void
    {
        [$next, $wasCalled] = $this->next();
        $guard = $this->guard(['secrets' => ['s3cret'], 'mode' => OriginGuard::MODE_REPORT]);

        $guard($this->req(['x-origin-secret' => 'wrong'], '/admin', 'POST'), $next);

        self::assertTrue($wasCalled());
        self::assertSame(200, \http_response_code());
        self::assertTrue($this->log->hasWarningThatContains('OriginGuard reported POST /admin from 203.0.113.9: origin secret mismatch'));
        self::assertSame('reported', $this->log->getRecords()[0]->context['outcome']);
    }

    public function testLogsNeverContainTheSecretOrThePresentedValue(): void
    {
        $guard = $this->guard(['secrets' => ['s3cret-value'], 'mode' => OriginGuard::MODE_REPORT]);
        [$next] = $this->next();

        $guard($this->req(['x-origin-secret' => 'presented-value']), $next);

        $records = $this->log->getRecords();
        self::assertCount(1, $records);
        $serialized = \json_encode($records[0]->toArray(), \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('s3cret-value', $serialized);
        self::assertStringNotContainsString('presented-value', $serialized);
    }

    public function testCustomHeaderName(): void
    {
        $guard = $this->guard(['secrets' => ['s3cret'], 'header' => 'X-Edge-Token']);
        [$next, $wasCalled] = $this->next();

        $guard($this->req(['x-edge-token' => 's3cret']), $next);

        self::assertTrue($wasCalled());
    }

    public function testUnknownModeIsRejectedUpFront(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OriginGuard::middleware(['secrets' => ['x'], 'mode' => 'audit']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function guard(array $config): Closure
    {
        $config['logger'] = new Logger('test', [$this->log], [new PsrLogMessageProcessor()]);

        /** @var array{header?: string, secrets?: array<mixed>, secretsFromEnv?: list<string>, except?: list<string>, mode?: string, logger?: Logger} $config */
        return OriginGuard::middleware($config);
    }

    /**
     * @param array<string, string> $headers
     */
    private function req(array $headers = [], string $path = '/api/x', string $method = 'GET'): Request
    {
        return new Request(method: $method, path: $path, headers: $headers, ip: '203.0.113.9');
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
