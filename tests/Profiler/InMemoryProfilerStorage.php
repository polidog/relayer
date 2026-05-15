<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Profiler;

use Polidog\Relayer\Profiler\Profile;
use Polidog\Relayer\Profiler\ProfilerStorage;

/**
 * Minimal in-memory {@see ProfilerStorage} test double.
 *
 * Keeps inserted profiles in `saved`, keyed by token when assigned that way,
 * otherwise indexed numerically. `recent()` returns them in reverse-insert
 * order so tests can mimic the file storage's newest-first contract.
 */
final class InMemoryProfilerStorage implements ProfilerStorage
{
    /** @var array<int|string, Profile> */
    public array $saved = [];

    public function save(Profile $profile): void
    {
        $this->saved[$profile->token] = $profile;
    }

    public function load(string $token): ?Profile
    {
        foreach ($this->saved as $profile) {
            if ($profile->token === $token) {
                return $profile;
            }
        }

        return null;
    }

    public function recent(int $limit = 20): array
    {
        return \array_slice(\array_reverse(\array_values($this->saved)), 0, $limit);
    }

    public function childrenOf(string $parentToken): array
    {
        if ('' === $parentToken) {
            return [];
        }

        $matches = \array_values(\array_filter(
            $this->saved,
            static fn (Profile $profile): bool => $profile->parentToken === $parentToken,
        ));

        \usort($matches, static fn (Profile $a, Profile $b): int => $a->startedAt <=> $b->startedAt);

        return $matches;
    }
}
