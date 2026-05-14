<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\Authenticator;
use Polidog\Relayer\Auth\Credentials;
use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Auth\NativePasswordHasher;
use Polidog\Relayer\Auth\TraceableAuthenticator;
use Polidog\Relayer\Auth\UserProvider;
use Polidog\Relayer\Profiler\RecordingProfiler;

final class TraceableAuthenticatorTest extends TestCase
{
    public function testSuccessfulAttemptRecordsTimedEvent(): void
    {
        $profiler = new RecordingProfiler();
        $profiler->beginProfile('/login', 'POST');

        $traceable = new TraceableAuthenticator($this->makeInner(), $profiler);
        $identity = $traceable->attempt('alice@example.com', 'secret123');

        self::assertNotNull($identity);
        $events = $profiler->currentProfile()?->getEvents() ?? [];
        self::assertCount(1, $events);
        self::assertSame('auth', $events[0]->collector);
        self::assertSame('attempt', $events[0]->label);
        self::assertSame('alice@example.com', $events[0]->payload['identifier']);
        self::assertTrue($events[0]->payload['success']);
        self::assertNotNull($events[0]->durationMs);
    }

    public function testFailedAttemptIsRecordedWithoutPassword(): void
    {
        $profiler = new RecordingProfiler();
        $profiler->beginProfile('/login', 'POST');

        $traceable = new TraceableAuthenticator($this->makeInner(), $profiler);
        $identity = $traceable->attempt('alice@example.com', 'WRONG');

        self::assertNull($identity);
        $events = $profiler->currentProfile()?->getEvents() ?? [];
        self::assertCount(1, $events);
        self::assertFalse($events[0]->payload['success']);
        // Defense in depth: the password value must never appear in the
        // recorded payload, even by accident.
        self::assertArrayNotHasKey('password', $events[0]->payload);
        self::assertStringNotContainsString('WRONG', \json_encode($events[0]->payload) ?: '');
    }

    public function testLoginIsRecorded(): void
    {
        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/login', 'POST');

        $traceable = new TraceableAuthenticator($this->makeInner(), $profiler);
        $traceable->login(new Identity(id: 'sso-42', displayName: 'OAuth User', roles: ['admin']));

        $events = $profile->getEvents();
        self::assertCount(1, $events);
        self::assertSame('login', $events[0]->label);
        self::assertSame('sso-42', $events[0]->payload['id']);
        self::assertSame(['admin'], $events[0]->payload['roles']);
    }

    public function testLogoutRecordsHadUserFlag(): void
    {
        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/logout', 'POST');

        $inner = $this->makeInner();
        $inner->login(new Identity(id: 1, displayName: 'Alice', roles: []));

        $traceable = new TraceableAuthenticator($inner, $profiler);
        $traceable->logout();

        $events = $profile->getEvents();
        self::assertCount(1, $events);
        self::assertSame('logout', $events[0]->label);
        self::assertTrue($events[0]->payload['hadUser']);
        self::assertFalse($inner->check(), 'logout must still cascade to the inner authenticator');
    }

    public function testReadMethodsAreNotRecorded(): void
    {
        // user()/check()/hasRole() get called many times per request. They
        // intentionally bypass the recorder to keep the timeline clean.
        $profiler = new RecordingProfiler();
        $profile = $profiler->beginProfile('/', 'GET');

        $traceable = new TraceableAuthenticator($this->makeInner(), $profiler);
        $traceable->user();
        $traceable->check();
        $traceable->hasRole('admin');
        $traceable->hasAnyRole(['admin', 'user']);

        self::assertCount(0, $profile->getEvents());
    }

    private function makeInner(): Authenticator
    {
        $hasher = new NativePasswordHasher();
        $provider = new class($hasher) implements UserProvider {
            private readonly string $aliceHash;

            public function __construct(NativePasswordHasher $hasher)
            {
                $this->aliceHash = $hasher->hash('secret123');
            }

            public function findByIdentifier(string $identifier): ?Credentials
            {
                if ('alice@example.com' !== $identifier) {
                    return null;
                }

                return new Credentials(
                    identity: new Identity(id: 1, displayName: 'Alice', roles: ['user']),
                    passwordHash: $this->aliceHash,
                );
            }
        };

        return new Authenticator($provider, $hasher, new ArraySessionStorage());
    }
}
