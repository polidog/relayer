<?php

declare(strict_types=1);

namespace Polidog\Relayer\Profiler;

/**
 * File-based {@see ProfilerStorage}: one JSON document per profile, named
 * `{token}.json`, kept under the configured directory.
 *
 * Writes are best-effort — failures are swallowed so a misconfigured cache
 * dir never blows up an in-flight (dev) request. Reads return `null` when
 * the file is missing or unreadable.
 */
final class FileProfilerStorage implements ProfilerStorage
{
    /**
     * Token shape accepted by {@see load()} as a filename component. Matches
     * the safe-token guard the router already applies at the URL boundary —
     * letters/digits/dashes/underscores only, so a corrupted profile JSON
     * carrying e.g. `../../etc/passwd` as a parentToken cannot redirect
     * subsequent loads outside the storage directory.
     */
    private const SAFE_TOKEN_PATTERN = '/^[a-zA-Z0-9_-]+$/';

    public function __construct(private readonly string $directory) {}

    public function save(Profile $profile): void
    {
        if (!\is_dir($this->directory) && !@\mkdir($this->directory, 0o755, true) && !\is_dir($this->directory)) {
            return;
        }

        $json = \json_encode(
            $profile->toArray(),
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR,
        );
        if (false === $json) {
            return;
        }

        @\file_put_contents($this->directory . '/' . $profile->token . '.json', $json);
    }

    public function load(string $token): ?Profile
    {
        // Defense in depth: even though the router validates inbound tokens,
        // load() is also called from the viewer with values pulled out of
        // stored profile JSON (e.g. parentToken on the detail view). A
        // tampered profile must not be able to redirect the read to an
        // arbitrary path component.
        if ('' === $token || !\preg_match(self::SAFE_TOKEN_PATTERN, $token)) {
            return null;
        }

        $path = $this->directory . '/' . $token . '.json';
        if (!\is_file($path)) {
            return null;
        }

        $raw = @\file_get_contents($path);
        if (false === $raw) {
            return null;
        }

        $data = \json_decode($raw, true);
        if (!\is_array($data)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        return Profile::fromArray($data);
    }

    public function recent(int $limit = 20): array
    {
        if (!\is_dir($this->directory)) {
            return [];
        }

        $entries = \glob($this->directory . '/*.json') ?: [];
        \usort($entries, static function (string $a, string $b): int {
            $am = @\filemtime($a) ?: 0;
            $bm = @\filemtime($b) ?: 0;

            return $bm <=> $am;
        });

        $out = [];
        foreach (\array_slice($entries, 0, $limit) as $path) {
            $profile = $this->load(\basename($path, '.json'));
            if (null !== $profile) {
                $out[] = $profile;
            }
        }

        return $out;
    }

    public function childrenOf(string $parentToken): array
    {
        if ('' === $parentToken || !\is_dir($this->directory)) {
            return [];
        }

        $out = [];
        foreach (\glob($this->directory . '/*.json') ?: [] as $path) {
            $profile = $this->load(\basename($path, '.json'));
            if (null !== $profile && $profile->parentToken === $parentToken) {
                $out[] = $profile;
            }
        }

        \usort($out, static fn (Profile $a, Profile $b): int => $a->startedAt <=> $b->startedAt);

        return $out;
    }
}
