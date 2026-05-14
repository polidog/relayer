<?php

declare(strict_types=1);

namespace App\Service;

final class UserRepository
{
    /** @var array<int, array{id: int, name: string, bio: string}> */
    private array $users = [
        1 => ['id' => 1, 'name' => 'Alice', 'bio' => 'Likes Haskell.'],
        2 => ['id' => 2, 'name' => 'Bob',   'bio' => 'Bash power user.'],
        3 => ['id' => 3, 'name' => 'Carol', 'bio' => 'Writes lots of PHP.'],
    ];

    /** @return list<array{id: int, name: string, bio: string}> */
    public function all(): array
    {
        return \array_values($this->users);
    }

    /** @return array{id: int, name: string, bio: string}|null */
    public function find(int $id): ?array
    {
        return $this->users[$id] ?? null;
    }
}
