<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Scaffold;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\AppConfigurator;
use Polidog\Relayer\Di\ContainerFactory;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;

/**
 * Pin the two halves of the `relayer container:compile` env contract:
 *
 *  1) `%env(VAR)%` parameter envelopes survive the {@see PhpDumper} dump as
 *     `getEnv()` calls and resolve at **load time** against the live env —
 *     the recommended pattern for secrets that are only available at
 *     runtime (Fly secrets, Cloud Run env, sidecar injectors, …). A build
 *     run where the var is unset, with the var only set at boot, must
 *     resolve to the boot-time value.
 *
 *  2) A configurator that reads `$_ENV` directly and stuffs a plain string
 *     into `setParameter()` bakes that string into the dump at compile
 *     time. A subsequent boot under a different env value gets the
 *     **frozen** build-time value — this is the silent-failure mode
 *     deployments hit when they expect runtime injection to reach the
 *     container. Documenting it as a test makes the contract executable
 *     so the README guidance ("use %env(...)% for runtime secrets") stays
 *     honest as Symfony evolves.
 */
final class ContainerCompileEnvBakingTest extends TestCase
{
    // `public` (not `private`) so the inline anonymous configurators below
    // — they extend AppConfigurator, not this test, so they have no
    // sibling access — can read the names from their `configure()` bodies.
    public const RUNTIME_VAR = 'RELAYER_TEST_RUNTIME_VAR';
    public const BAKED_VAR = 'RELAYER_TEST_BAKED_VAR';

    private string $project;

    protected function setUp(): void
    {
        $this->project = \sys_get_temp_dir() . '/relayer-envbake-' . \bin2hex(\random_bytes(6));
        \mkdir($this->project, 0o755, true);
        $this->clearTestEnv();
    }

    protected function tearDown(): void
    {
        self::removeTree($this->project);
        $this->clearTestEnv();
    }

    public function testEnvPlaceholderParameterResolvesAtRuntimeInDumpedContainer(): void
    {
        $configurator = new class($this->project) extends AppConfigurator {
            public function configure(ContainerBuilder $container): void
            {
                // The recommended pattern: pass the placeholder string,
                // not the resolved value. PhpDumper emits it as a getEnv()
                // call so the dumped container reads the env on access.
                $container->setParameter('app.runtime_secret', '%env(' . ContainerCompileEnvBakingTest::RUNTIME_VAR . ')%');
            }
        };

        // Build-time value: empty (mirrors the "secret only injected at
        // boot, not in the build pipeline" deployment model).
        $this->setEnv(self::RUNTIME_VAR, '');

        $container = ContainerFactory::create($this->project, $configurator, false);
        [$fqcn, $dumpFile] = $this->dumpToFile($container, 'EnvPlaceholderDump');

        // Switch the env to the "runtime" value, then load the dump. A
        // baked-in value would still report the build-time empty string;
        // a getEnv()-emitted dump picks up the new value.
        $this->setEnv(self::RUNTIME_VAR, 'at-runtime');

        $loaded = $this->loadDumpedContainer($fqcn, $dumpFile);

        self::assertSame('at-runtime', $loaded->getParameter('app.runtime_secret'));
    }

    public function testDirectEnvReadInConfiguratorIsBakedAtCompileTime(): void
    {
        $configurator = new class($this->project) extends AppConfigurator {
            public function configure(ContainerBuilder $container): void
            {
                // The trap: the value is read at build time and embedded
                // as a plain string. The dump never queries the env again.
                $raw = $_ENV[ContainerCompileEnvBakingTest::BAKED_VAR] ?? '';
                $container->setParameter(
                    'app.baked_secret',
                    \is_string($raw) ? $raw : '',
                );
            }
        };

        $this->setEnv(self::BAKED_VAR, 'at-build-time');

        $container = ContainerFactory::create($this->project, $configurator, false);
        [$fqcn, $dumpFile] = $this->dumpToFile($container, 'EnvBakedDump');

        // Production-style runtime injection. The dump must NOT see this
        // value — the configurator never re-runs against the dumped
        // container. Asserting the build-time value documents the
        // frozen-at-bake-time contract: this is exactly the silent-failure
        // mode the README "runtime secrets" guidance warns against.
        $this->setEnv(self::BAKED_VAR, 'at-runtime');

        $loaded = $this->loadDumpedContainer($fqcn, $dumpFile);

        self::assertSame('at-build-time', $loaded->getParameter('app.baked_secret'));
    }

    private function loadDumpedContainer(string $fqcn, string $artifact): Container
    {
        require $artifact;
        self::assertTrue(\class_exists($fqcn, false), "Dumped class {$fqcn} did not load");

        $loaded = new $fqcn();
        self::assertInstanceOf(Container::class, $loaded);

        return $loaded;
    }

    /**
     * @return array{string, string} The FQCN to instantiate, and the file
     *                               path the dump was written to. The class name is randomised so
     *                               sibling tests can require dumps in the same process without
     *                               redeclaring (PhpDumper-emitted classes are not anonymous).
     */
    private function dumpToFile(ContainerBuilder $container, string $classPrefix): array
    {
        $className = $classPrefix . '_' . \bin2hex(\random_bytes(4));
        $namespace = 'Polidog\Relayer\Tests\Scaffold\Dump';

        $dumped = (new PhpDumper($container))->dump([
            'class' => $className,
            'namespace' => $namespace,
        ]);
        self::assertIsString($dumped, 'PhpDumper produced multiple files; this test expects a single class.');

        $file = $this->project . '/' . $className . '.php';
        \file_put_contents($file, $dumped);

        return [$namespace . '\\' . $className, $file];
    }

    private function setEnv(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        \putenv($name . '=' . $value);
    }

    private function clearTestEnv(): void
    {
        foreach ([self::RUNTIME_VAR, self::BAKED_VAR] as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
            \putenv($name);
        }
    }

    private static function removeTree(string $path): void
    {
        if (!\file_exists($path)) {
            return;
        }
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);

            return;
        }
        $entries = \scandir($path);
        if (false === $entries) {
            return;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }
        @\rmdir($path);
    }
}
