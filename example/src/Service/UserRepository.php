<?php

declare(strict_types=1);

namespace App\Service;

use Polidog\Relayer\Db\Database;

/**
 * Users list/detail backed by the framework {@see Database} layer.
 *
 * The constructor takes `Database` directly — Relayer registers it (and
 * wraps it in CachingDatabase, plus TraceableDatabase in dev) the moment
 * `DATABASE_DSN` is set, so services.yaml autowiring resolves it with no
 * extra config. SQLite keeps the demo zero-setup: the schema is created
 * and seeded on first use, so a fresh checkout just works.
 *
 * In dev, open /_profiler after a request to /users(/N) to see the exact
 * SELECT this issues — and note a second call in the same request is
 * served from CachingDatabase rather than re-querying.
 */
final class UserRepository
{
    private bool $ensured = false;

    public function __construct(private readonly Database $db) {}

    /** @return list<array{id: int, name: string, bio: string}> */
    public function all(): array
    {
        $this->ensureSeeded();

        return \array_map(
            self::shape(...),
            $this->db->fetchAll('SELECT id, name, bio FROM users ORDER BY id'),
        );
    }

    /** @return array{id: int, name: string, bio: string}|null */
    public function find(int $id): ?array
    {
        $this->ensureSeeded();

        $row = $this->db->fetchOne(
            'SELECT id, name, bio FROM users WHERE id = :id',
            ['id' => $id],
        );

        return null === $row ? null : self::shape($row);
    }

    /**
     * Idempotent schema + seed. `perform()` handles DDL; the seed runs in
     * a single `transactional()` so a partial insert can't leave the demo
     * half-populated. Guarded by a row count so steady-state requests only
     * pay the cheap `CREATE TABLE IF NOT EXISTS` + `COUNT(*)`.
     */
    private function ensureSeeded(): void
    {
        if ($this->ensured) {
            return;
        }

        $this->db->perform(
            'CREATE TABLE IF NOT EXISTS users ('
            . 'id INTEGER PRIMARY KEY, '
            . 'name TEXT NOT NULL, '
            . 'bio TEXT NOT NULL)',
        );

        $count = (int) $this->db->fetchValue('SELECT COUNT(*) FROM users');
        if (0 === $count) {
            $this->db->transactional(static function (Database $tx): void {
                $seed = [
                    [1, 'Alice', 'Likes Haskell.'],
                    [2, 'Bob', 'Bash power user.'],
                    [3, 'Carol', 'Writes lots of PHP.'],
                ];
                foreach ($seed as [$id, $name, $bio]) {
                    $tx->perform(
                        'INSERT INTO users (id, name, bio) VALUES (:id, :name, :bio)',
                        ['id' => $id, 'name' => $name, 'bio' => $bio],
                    );
                }
            });
        }

        $this->ensured = true;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: int, name: string, bio: string}
     */
    private static function shape(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'bio' => (string) $row['bio'],
        ];
    }
}
