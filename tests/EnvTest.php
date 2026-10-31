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

    public function testImportCopiesAProcessVariableIntoEnvOnly(): void
    {
        \putenv('RELAYER_ENVTEST=from-getenv');

        Env::importProcessEnvironment();

        self::assertSame('from-getenv', $_ENV['RELAYER_ENVTEST'] ?? null);
        self::assertArrayNotHasKey('RELAYER_ENVTEST', $_SERVER);
    }

    public function testImportKeepsValuesAlreadyInTheSuperglobals(): void
    {
        \putenv('RELAYER_ENVTEST=from-getenv');

        $_ENV['RELAYER_ENVTEST'] = 'from-env';
        Env::importProcessEnvironment();
        self::assertSame('from-env', $_ENV['RELAYER_ENVTEST']);

        // A $_SERVER value must stay ahead of getenv(), as in read().
        unset($_ENV['RELAYER_ENVTEST']);
        $_SERVER['RELAYER_ENVTEST'] = 'from-server';
        Env::importProcessEnvironment();
        self::assertArrayNotHasKey('RELAYER_ENVTEST', $_ENV);
        self::assertSame('from-server', Env::read('RELAYER_ENVTEST'));
    }

    public function testImportSkipsHeaderLikeAndDotenvBookkeepingNames(): void
    {
        \putenv('HTTP_RELAYER_ENVTEST=from-client');
        \putenv('SYMFONY_DOTENV_RELAYER_ENVTEST=APP_ENV');

        try {
            Env::importProcessEnvironment();

            self::assertArrayNotHasKey('HTTP_RELAYER_ENVTEST', $_ENV);
            self::assertArrayNotHasKey('SYMFONY_DOTENV_RELAYER_ENVTEST', $_ENV);
        } finally {
            \putenv('HTTP_RELAYER_ENVTEST');
            \putenv('SYMFONY_DOTENV_RELAYER_ENVTEST');
        }
    }

    public function testImportAcceptsANumericVariableName(): void
    {
        // getenv() returns this name as an int key, which must not reach
        // str_starts_with() under strict_types.
        \putenv('7301=numeric');

        try {
            Env::importProcessEnvironment();

            self::assertSame('numeric', $_ENV[7301] ?? null);
        } finally {
            \putenv('7301');
            unset($_ENV[7301]);
        }
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
