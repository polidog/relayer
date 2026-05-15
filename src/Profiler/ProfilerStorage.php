<?php

declare(strict_types=1);

namespace Polidog\Relayer\Profiler;

/**
 * Persistence layer for {@see Profile} records.
 *
 * The default implementation ({@see FileProfilerStorage}) writes each
 * Profile as `var/cache/profiler/{token}.json`. Adapters for Redis / SQLite
 * etc. can be swapped in by re-aliasing `ProfilerStorage::class` in the
 * app's `AppConfigurator`.
 */
interface ProfilerStorage
{
    public function save(Profile $profile): void;

    public function load(string $token): ?Profile;

    /**
     * Return the most recently saved profiles, newest first.
     *
     * @return list<Profile>
     */
    public function recent(int $limit = 20): array;

    /**
     * Return profiles whose `parentToken` matches — i.e. the sub-requests
     * that the given profile spawned (e.g. `<X defer />` fetches initiated
     * from the parent page render). Oldest first so the parent's detail
     * view can lay them out in dispatch order.
     *
     * @return list<Profile>
     */
    public function childrenOf(string $parentToken): array;
}
