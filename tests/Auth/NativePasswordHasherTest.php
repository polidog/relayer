<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\NativePasswordHasher;

final class NativePasswordHasherTest extends TestCase
{
    public function testHashAndVerifyRoundtrip(): void
    {
        $hasher = new NativePasswordHasher();
        $hash = $hasher->hash('correct-horse-battery-staple');

        self::assertNotSame('correct-horse-battery-staple', $hash);
        self::assertTrue($hasher->verify('correct-horse-battery-staple', $hash));
        self::assertFalse($hasher->verify('wrong-password', $hash));
    }

    public function testHashesAreSaltedAndNonDeterministic(): void
    {
        $hasher = new NativePasswordHasher();

        self::assertNotSame(
            $hasher->hash('same-input'),
            $hasher->hash('same-input'),
            'Two calls with identical input must produce different hashes (random salt).',
        );
    }

    public function testNeedsRehashReturnsFalseForFreshHash(): void
    {
        $hasher = new NativePasswordHasher();
        $hash = $hasher->hash('p@ss');

        self::assertFalse($hasher->needsRehash($hash));
    }
}
