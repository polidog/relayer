<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Env;

final class EnvTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['RELAYER_ENVTEST'], $_SERVER['RELAYER_ENVTEST']);
        \putenv('RELAYER_ENVTEST');
    }

    public function testReadWalksEnvThenServerThenGetenv(): void
    {
        self::assertNull(Env::read('RELAYER_ENVTEST'));

        \putenv('RELAYER_ENVTEST=from-getenv');
        self::assertSame('from-getenv', Env::read('RELAYER_ENVTEST'));

        $_SERVER['RELAYER_ENVTEST'] = 'from-server';
        self::assertSame('from-server', Env::read('RELAYER_ENVTEST'));

        $_ENV['RELAYER_ENVTEST'] = 'from-env';
        self::assertSame('from-env', Env::read('RELAYER_ENVTEST'));
    }

    public function testReadTrimsAndTreatsBlankAsUnset(): void
    {
        $_ENV['RELAYER_ENVTEST'] = " value\n";
        self::assertSame('value', Env::read('RELAYER_ENVTEST'));

        $_ENV['RELAYER_ENVTEST'] = "  \t";
        self::assertNull(Env::read('RELAYER_ENVTEST'));
    }

    public function testReadListSplitsOnCommasAndDropsEmptyEntries(): void
    {
        $_ENV['RELAYER_ENVTEST'] = ' a, b ,,c, ';
        self::assertSame(['a', 'b', 'c'], Env::readList('RELAYER_ENVTEST'));

        $_ENV['RELAYER_ENVTEST'] = '';
        self::assertSame([], Env::readList('RELAYER_ENVTEST'));
    }
}
