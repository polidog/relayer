<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\Identity;

final class IdentityTest extends TestCase
{
    public function testHasRoleMatchesExactCase(): void
    {
        $identity = new Identity(id: 1, displayName: 'Alice', roles: ['user', 'admin']);

        self::assertTrue($identity->hasRole('admin'));
        self::assertFalse($identity->hasRole('Admin'));
        self::assertFalse($identity->hasRole('other'));
    }

    public function testFromArrayRoundtripsToArray(): void
    {
        $original = new Identity(id: 7, displayName: 'Bob', roles: ['user']);
        $reconstructed = Identity::fromArray($original->toArray());

        self::assertNotNull($reconstructed);
        self::assertSame(7, $reconstructed->id);
        self::assertSame('Bob', $reconstructed->displayName);
        self::assertSame(['user'], $reconstructed->roles);
    }

    public function testFromArrayPreservesStringId(): void
    {
        $identity = Identity::fromArray([
            'id' => 'abc-123',
            'displayName' => 'Carol',
            'roles' => [],
        ]);

        self::assertNotNull($identity);
        self::assertSame('abc-123', $identity->id);
    }

    public function testFromArrayRejectsMalformedPayload(): void
    {
        // Missing displayName
        self::assertNull(Identity::fromArray(['id' => 1, 'roles' => []]));
        // Non-scalar id
        self::assertNull(Identity::fromArray(['id' => [], 'displayName' => 'x', 'roles' => []]));
        // Roles not an array
        self::assertNull(Identity::fromArray(['id' => 1, 'displayName' => 'x', 'roles' => 'admin']));
    }

    public function testFromArrayFiltersNonStringRoles(): void
    {
        $identity = Identity::fromArray([
            'id' => 1,
            'displayName' => 'x',
            'roles' => ['admin', 123, null, 'user'],
        ]);

        self::assertNotNull($identity);
        self::assertSame(['admin', 'user'], $identity->roles);
    }
}
