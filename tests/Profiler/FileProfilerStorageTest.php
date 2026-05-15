<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Profiler;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Profiler\Event;
use Polidog\Relayer\Profiler\FileProfilerStorage;
use Polidog\Relayer\Profiler\Profile;

final class FileProfilerStorageTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/relayer-profiler-' . \uniqid();
    }

    protected function tearDown(): void
    {
        if (!\is_dir($this->dir)) {
            return;
        }
        foreach (\glob($this->dir . '/*') ?: [] as $entry) {
            @\unlink($entry);
        }
        @\rmdir($this->dir);
    }

    public function testSaveAndLoadRoundtrip(): void
    {
        $storage = new FileProfilerStorage($this->dir);
        $profile = new Profile('abc123', '/users', 'GET', \microtime(true));
        $profile->addEvent(new Event('route', 'match', ['pattern' => '/users'], \microtime(true)));
        $profile->end(200);

        $storage->save($profile);

        $loaded = $storage->load('abc123');
        self::assertNotNull($loaded);
        self::assertSame('abc123', $loaded->token);
        self::assertSame('/users', $loaded->url);
        self::assertSame('GET', $loaded->method);
        self::assertSame(200, $loaded->getStatusCode());
        self::assertCount(1, $loaded->getEvents());
        self::assertSame('route', $loaded->getEvents()[0]->collector);
    }

    public function testLoadReturnsNullForMissingToken(): void
    {
        $storage = new FileProfilerStorage($this->dir);

        self::assertNull($storage->load('does-not-exist'));
    }

    public function testRecentReturnsNewestFirst(): void
    {
        $storage = new FileProfilerStorage($this->dir);

        $older = new Profile('older', '/a', 'GET', \microtime(true));
        $older->end(200);
        $storage->save($older);

        \usleep(1_100_000); // mtime resolution is 1s on some filesystems
        $newer = new Profile('newer', '/b', 'GET', \microtime(true));
        $newer->end(200);
        $storage->save($newer);

        $recent = $storage->recent(10);
        self::assertCount(2, $recent);
        self::assertSame('newer', $recent[0]->token);
        self::assertSame('older', $recent[1]->token);
    }

    public function testRecentRespectsLimit(): void
    {
        $storage = new FileProfilerStorage($this->dir);
        for ($i = 0; $i < 5; ++$i) {
            $profile = new Profile('t' . $i, '/', 'GET', \microtime(true));
            $profile->end(200);
            $storage->save($profile);
        }

        self::assertCount(3, $storage->recent(3));
    }

    public function testRecentOnMissingDirectoryReturnsEmpty(): void
    {
        $storage = new FileProfilerStorage($this->dir . '/never-created');

        self::assertSame([], $storage->recent());
    }

    public function testParentTokenRoundtrips(): void
    {
        $storage = new FileProfilerStorage($this->dir);
        $parent = new Profile('par1234567890ab', '/', 'GET', \microtime(true));
        $parent->end(200);
        $child = new Profile('chi1234567890ab', '/', 'POST', \microtime(true), parentToken: 'par1234567890ab');
        $child->end(200);

        $storage->save($parent);
        $storage->save($child);

        $loaded = $storage->load('chi1234567890ab');
        self::assertNotNull($loaded);
        self::assertSame('par1234567890ab', $loaded->parentToken);
    }

    public function testChildrenOfReturnsMatchingProfilesOldestFirst(): void
    {
        $storage = new FileProfilerStorage($this->dir);

        $parent = new Profile('par1234567890ab', '/page', 'GET', 1000.0);
        $parent->end(200);
        $storage->save($parent);

        // Two children plus an unrelated profile that must NOT come back.
        $childA = new Profile('chiAAAAAAAAAAAA', '/page', 'POST', 1001.0, parentToken: 'par1234567890ab');
        $childA->end(200);
        $childB = new Profile('chiBBBBBBBBBBBB', '/page', 'POST', 1002.0, parentToken: 'par1234567890ab');
        $childB->end(200);
        $unrelated = new Profile('unrelated123456', '/other', 'GET', 1003.0);
        $unrelated->end(200);
        $storage->save($childB); // Save out of order to confirm sort-by-startedAt.
        $storage->save($childA);
        $storage->save($unrelated);

        $children = $storage->childrenOf('par1234567890ab');
        self::assertCount(2, $children);
        self::assertSame('chiAAAAAAAAAAAA', $children[0]->token, 'oldest child first');
        self::assertSame('chiBBBBBBBBBBBB', $children[1]->token);
    }

    public function testChildrenOfWithEmptyTokenReturnsEmpty(): void
    {
        $storage = new FileProfilerStorage($this->dir);

        self::assertSame([], $storage->childrenOf(''));
    }
}
