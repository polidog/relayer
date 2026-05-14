<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth;

/**
 * Immutable principal stored in the session after a successful login.
 *
 * Holds only the information the framework needs to authorize subsequent
 * requests: a stable user id, a display name suitable for rendering, and
 * a list of role strings consulted by {@see Auth} / {@see Authenticator::hasRole()}.
 *
 * Never store a password hash here — credentials only flow through
 * {@see UserProvider} during the login handshake.
 */
final readonly class Identity
{
    /**
     * @param int|string    $id          stable primary key (database id, uuid)
     * @param string        $displayName name to render in the UI
     * @param array<string> $roles       authorization roles (case-sensitive)
     */
    public function __construct(
        public int|string $id,
        public string $displayName,
        public array $roles = [],
    ) {}

    public function hasRole(string $role): bool
    {
        return \in_array($role, $this->roles, true);
    }

    /**
     * @return array{id: int|string, displayName: string, roles: array<string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'displayName' => $this->displayName,
            'roles' => $this->roles,
        ];
    }

    /**
     * @param array{id?: mixed, displayName?: mixed, roles?: mixed} $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $id = $payload['id'] ?? null;
        $displayName = $payload['displayName'] ?? null;
        $roles = $payload['roles'] ?? [];

        if (!\is_int($id) && !\is_string($id)) {
            return null;
        }
        if (!\is_string($displayName)) {
            return null;
        }
        if (!\is_array($roles)) {
            return null;
        }

        $roleStrings = [];
        foreach ($roles as $role) {
            if (\is_string($role)) {
                $roleStrings[] = $role;
            }
        }

        return new self($id, $displayName, $roleStrings);
    }
}
