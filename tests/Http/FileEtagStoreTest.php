<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\FileEtagStore;

final class FileEtagStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/usephp-etag-' . \uniqid();
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->dir);
    }

    public function testGetReturnsNullWhenKeyAbsent(): void
    {
        $store = new FileEtagStore($this->dir);

        self::assertNull($store->get('missing'));
    }

    public function testSetThenGetRoundTrips(): void
    {
        $store = new FileEtagStore($this->dir);

        $store->set('home', 'abc123');

        self::assertSame('abc123', $store->get('home'));
    }

    public function testSetCreatesDirectoryOnDemand(): void
    {
        self::assertFalse(\is_dir($this->dir));

        (new FileEtagStore($this->dir))->set('home', 'xyz');

        self::assertTrue(\is_dir($this->dir));
    }

    public function testSetOverwritesExistingValue(): void
    {
        $store = new FileEtagStore($this->dir);

        $store->set('home', 'v1');
        $store->set('home', 'v2');

        self::assertSame('v2', $store->get('home'));
    }

    public function testForgetRemovesEntry(): void
    {
        $store = new FileEtagStore($this->dir);

        $store->set('home', 'v1');
        $store->forget('home');

        self::assertNull($store->get('home'));
    }

    public function testForgetIsIdempotent(): void
    {
        $store = new FileEtagStore($this->dir);

        $store->forget('nope'); // should not throw

        self::assertNull($store->get('nope'));
    }

    public function testKeysWithSpecialCharsAreSafe(): void
    {
        $store = new FileEtagStore($this->dir);

        $store->set('a/b/c?x=1 ', 'value');

        self::assertSame('value', $store->get('a/b/c?x=1 '));
    }

    private function rrmdir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            \is_dir($path) ? $this->rrmdir($path) : \unlink($path);
        }
        \rmdir($dir);
    }
}
