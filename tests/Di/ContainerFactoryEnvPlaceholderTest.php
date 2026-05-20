<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Di;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Di\ContainerFactory;
use Polidog\Relayer\InjectorContainer;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Scaffold\ContainerCompileCommand;
use Polidog\Relayer\Tests\Fixtures\ServiceWithStringArg;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainerInterface;

/**
 * Regression coverage for Symfony's `%env(VAR)%` placeholder in
 * `config/services.yaml`.
 *
 * The two compile modes are deliberately asymmetric:
 *  - dev (and the missing-dump prod fallback) hand the ContainerBuilder
 *    back directly, so placeholders MUST be resolved at compile time —
 *    Symfony's runtime `getEnv()` substitution only lives in
 *    PhpDumper-generated classes. Without that, both `getParameter()` and
 *    service args would hand back raw `env_xxx_VAR_xxx` placeholders.
 *  - the dump path (`relayer container:compile`) MUST keep placeholders
 *    intact so PhpDumper wires them to per-request `getEnv()` calls in
 *    the dumped class; otherwise env values would be baked in at deploy
 *    and runtime env changes would be silently ignored.
 *
 * Pre-fix, `ContainerFactory::create()` called `compile()` (= `compile(false)`)
 * unconditionally, so the dev case returned placeholders. This test pins
 * both halves of the contract via {@see ContainerFactory}'s only two
 * callers — {@see Relayer::boot()} (dev) and {@see ContainerCompileCommand}
 * (dump).
 */
final class ContainerFactoryEnvPlaceholderTest extends TestCase
{
    private const ENV_KEYS = ['APP_ENV', 'RELAYER_ENV_FIXTURE'];

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->clearEnv();
        $this->projectRoot = \sys_get_temp_dir() . '/relayer-env-' . \bin2hex(\random_bytes(6));
        \mkdir($this->projectRoot . '/src/Pages', 0o755, true);
        \mkdir($this->projectRoot . '/config', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->projectRoot);
        $this->clearEnv();
    }

    public function testDevBootResolvesEnvPlaceholderInServiceArgument(): void
    {
        \file_put_contents(
            $this->projectRoot . '/.env',
            "APP_ENV=dev\nRELAYER_ENV_FIXTURE=injected-value\n",
        );
        \file_put_contents(
            $this->projectRoot . '/config/services.yaml',
            <<<'YAML'
            services:
              _defaults:
                autowire: false
                public: true

              Polidog\Relayer\Tests\Fixtures\ServiceWithStringArg:
                arguments:
                  - '%env(RELAYER_ENV_FIXTURE)%'
            YAML,
        );

        $container = $this->bootContainer();

        $service = $container->get(ServiceWithStringArg::class);
        self::assertInstanceOf(ServiceWithStringArg::class, $service);
        self::assertSame(
            'injected-value',
            $service->value,
            'dev boot must resolve %env(RELAYER_ENV_FIXTURE)% to the live env value, not the raw placeholder',
        );
    }

    public function testDevBootResolvesEnvPlaceholderInGetParameter(): void
    {
        \file_put_contents(
            $this->projectRoot . '/.env',
            "APP_ENV=dev\nRELAYER_ENV_FIXTURE=param-value\n",
        );
        \file_put_contents(
            $this->projectRoot . '/config/services.yaml',
            <<<'YAML'
            parameters:
              app.env_fixture: '%env(RELAYER_ENV_FIXTURE)%'
            YAML,
        );

        $container = $this->bootContainer();

        self::assertSame(
            'param-value',
            $container->getParameter('app.env_fixture'),
            'dev boot must resolve %env(RELAYER_ENV_FIXTURE)% on direct getParameter() access too',
        );
    }

    /**
     * Dump-path counterpart: the compiled artifact must NOT bake in the
     * env value seen at compile time. `relayer container:compile` runs at
     * deploy with the build-time env; the value an app actually reads has
     * to come from the per-request env. Run in a separate process: the
     * test `require`s the generated class and touches process-wide env.
     */
    #[RunInSeparateProcess]
    public function testCompiledDumpReadsEnvAtRuntimeNotAtBuildTime(): void
    {
        \file_put_contents(
            $this->projectRoot . '/config/services.yaml',
            <<<'YAML'
            services:
              _defaults:
                autowire: false
                public: true

              Polidog\Relayer\Tests\Fixtures\ServiceWithStringArg:
                arguments:
                  - '%env(RELAYER_ENV_FIXTURE)%'
            YAML,
        );

        // Build-time env: this is what container:compile sees.
        $_ENV['RELAYER_ENV_FIXTURE'] = 'build-time';
        $_SERVER['RELAYER_ENV_FIXTURE'] = 'build-time';

        $lines = [];
        $status = ContainerCompileCommand::run(
            [],
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            $this->projectRoot,
        );
        self::assertSame(0, $status, \implode("\n", $lines));

        // Swap to a different env value. A baked-in dump would still see
        // 'build-time'; a correctly placeholder-preserving dump reads the
        // new value at service construction time.
        $_ENV['RELAYER_ENV_FIXTURE'] = 'runtime-value';
        $_SERVER['RELAYER_ENV_FIXTURE'] = 'runtime-value';

        $artifact = $this->projectRoot . '/' . Relayer::COMPILED_CONTAINER_FILE;
        self::assertFileExists($artifact);

        $class = Relayer::COMPILED_CONTAINER_CLASS;
        if (!\class_exists($class, false)) {
            require $artifact;
        }
        self::assertTrue(\class_exists($class, false));

        $container = new $class();
        self::assertInstanceOf(SymfonyContainerInterface::class, $container);

        $service = $container->get(ServiceWithStringArg::class);
        self::assertInstanceOf(ServiceWithStringArg::class, $service);
        self::assertSame(
            'runtime-value',
            $service->value,
            'dumped container must read RELAYER_ENV_FIXTURE per-request, not the build-time value',
        );
    }

    /**
     * Unwrap the Symfony container from the {@see InjectorContainer}
     * PSR-11 adapter Relayer hands the router. Tests need direct
     * `getParameter()` access (PSR-11 doesn't expose it), and `get()` on
     * the inner container is the same code path Symfony-bound services hit
     * anyway.
     */
    private function bootContainer(): SymfonyContainerInterface
    {
        $router = Relayer::boot($this->projectRoot);
        self::assertInstanceOf(AppRouter::class, $router);

        $injector = (new ReflectionProperty(AppRouter::class, 'container'))->getValue($router);
        self::assertInstanceOf(InjectorContainer::class, $injector);

        $inner = (new ReflectionProperty(InjectorContainer::class, 'container'))->getValue($injector);
        self::assertInstanceOf(SymfonyContainerInterface::class, $inner);

        return $inner;
    }

    private function clearEnv(): void
    {
        foreach (self::ENV_KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            \putenv($key);
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir . '/' . $entry;
            \is_dir($path) ? $this->rrmdir($path) : @\unlink($path);
        }
        @\rmdir($dir);
    }
}
