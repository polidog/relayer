<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

/**
 * Filesystem-backed EtagStore.
 *
 * One file per key, named by `sha1($key)` so any string is safe to use as a
 * key. Writes are atomic (write to a temp file, then `rename`) so concurrent
 * readers never observe a half-written value.
 */
final class FileEtagStore implements EtagStore
{
    public function __construct(private readonly string $directory)
    {
    }

    public function get(string $key): ?string
    {
        $path = $this->path($key);
        if (!\is_file($path)) {
            return null;
        }

        $value = @\file_get_contents($path);
        if ($value === false || $value === '') {
            return null;
        }

        return $value;
    }

    public function set(string $key, string $etag): void
    {
        $this->ensureDirectory();

        $path = $this->path($key);
        $tmp = $path . '.' . \bin2hex(\random_bytes(4)) . '.tmp';

        if (@\file_put_contents($tmp, $etag, \LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write ETag file: $tmp");
        }
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);
            throw new \RuntimeException("Failed to publish ETag file: $path");
        }
    }

    public function forget(string $key): void
    {
        $path = $this->path($key);
        if (\is_file($path)) {
            @\unlink($path);
        }
    }

    private function path(string $key): string
    {
        return $this->directory . '/' . \sha1($key) . '.etag';
    }

    private function ensureDirectory(): void
    {
        if (\is_dir($this->directory)) {
            return;
        }
        if (!@\mkdir($this->directory, 0o755, true) && !\is_dir($this->directory)) {
            throw new \RuntimeException("Failed to create ETag directory: {$this->directory}");
        }
    }
}
