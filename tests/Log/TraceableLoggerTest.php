<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Log;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Log\TraceableLogger;
use Polidog\Relayer\Profiler\RecordingProfiler;
use RuntimeException;

final class TraceableLoggerTest extends TestCase
{
    public function testRecordsLevelAndInterpolatedMessageThenDelegates(): void
    {
        $inner = new SpyLogger();
        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $logger = new TraceableLogger($inner, $profiler);
        $logger->info('user {id} signed in', ['id' => 42]);

        $events = $profile->getEvents();
        self::assertCount(1, $events);
        self::assertSame('log', $events[0]->collector);
        self::assertSame('info', $events[0]->label);
        self::assertSame('user 42 signed in', $events[0]->payload['message']);
        self::assertSame(['id' => 42], $events[0]->payload['context']);

        // The real sink receives the original, uninterpolated PSR-3 args.
        self::assertCount(1, $inner->records);
        self::assertSame('info', $inner->records[0]['level']);
        self::assertSame('user {id} signed in', $inner->records[0]['message']);
        self::assertSame(['id' => 42], $inner->records[0]['context']);
    }

    public function testContextOmittedFromPayloadWhenEmpty(): void
    {
        $inner = new SpyLogger();
        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        (new TraceableLogger($inner, $profiler))->error('boom');

        $payload = $profile->getEvents()[0]->payload;
        self::assertSame('boom', $payload['message']);
        self::assertArrayNotHasKey('context', $payload);
    }

    public function testSensitiveContextIsRedactedInProfileButNotInInnerLogger(): void
    {
        $inner = new SpyLogger();
        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $logger = new TraceableLogger($inner, $profiler);
        $logger->warning('login failed', [
            'user' => 'alice',
            'password' => 'hunter2',
            'api_key' => 'sk-live-123',
        ]);

        $recorded = $profile->getEvents()[0]->payload['context'];
        self::assertIsArray($recorded);
        self::assertSame('alice', $recorded['user']);
        self::assertSame('***', $recorded['password']);
        self::assertSame('***', $recorded['api_key']);
        self::assertStringNotContainsString(
            'hunter2',
            \json_encode($profile->getEvents()[0]->payload, \JSON_THROW_ON_ERROR),
        );

        // The application's chosen context still reaches the real sink.
        self::assertSame('hunter2', $inner->records[0]['context']['password']);
    }

    public function testThrowableContextIsReducedForTheProfile(): void
    {
        $inner = new SpyLogger();
        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $ex = new RuntimeException('disk full');
        (new TraceableLogger($inner, $profiler))->critical('write failed', ['exception' => $ex]);

        $recorded = $profile->getEvents()[0]->payload['context'];
        self::assertIsArray($recorded);
        self::assertSame(RuntimeException::class . ': disk full', $recorded['exception']);
        // Inner logger keeps the real Throwable for Monolog to format.
        self::assertSame($ex, $inner->records[0]['context']['exception']);
    }
}
