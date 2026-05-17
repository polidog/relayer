<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests;

use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\AppConfigurator;
use Polidog\Relayer\Http\Client\CachingHttpClient;
use Polidog\Relayer\Http\Client\HttpClient;
use Polidog\Relayer\Log\TraceableLogger;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\AppRouter;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class RelayerBootTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = \sys_get_temp_dir() . '/relayer-test-' . \uniqid();
        \mkdir($this->projectRoot . '/src/Pages', 0o755, true);
        \file_put_contents(
            $this->projectRoot . '/.env',
            "APP_ENV=dev\nFRAMEWORK_TEST_VALUE=hello\n",
        );
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->projectRoot);
        unset($_ENV['APP_ENV'], $_ENV['FRAMEWORK_TEST_VALUE'], $_SERVER['APP_ENV'], $_SERVER['FRAMEWORK_TEST_VALUE']);
    }

    public function testBootReturnsAppRouterAndLoadsEnv(): void
    {
        $router = Relayer::boot($this->projectRoot);

        self::assertInstanceOf(AppRouter::class, $router);
        self::assertSame('hello', $_ENV['FRAMEWORK_TEST_VALUE'] ?? null);
        self::assertSame('dev', $_ENV['APP_ENV'] ?? null);
    }

    public function testBootPinsPsxCacheToProjectRootVarCachePsx(): void
    {
        // Regression for #21: page PSX cache must land in
        // <projectRoot>/var/cache/psx (beside the component manifest), not
        // <projectRoot>/src/var/cache/psx from AppRouter's dirname() default.
        $router = Relayer::boot($this->projectRoot);

        $property = new ReflectionProperty(AppRouter::class, 'psxCacheDir');

        self::assertSame(
            $this->projectRoot . '/var/cache/psx',
            $property->getValue($router),
        );
    }

    public function testHttpClientAliasResolvesToCachingDecorator(): void
    {
        // Regression: the HttpClient contract must always be satisfiable
        // (it takes no required config, unlike Database) and must resolve
        // to the request-scoped caching decorator at the outermost layer.
        $router = Relayer::boot($this->projectRoot);

        $property = new ReflectionProperty(AppRouter::class, 'container');
        $container = $property->getValue($router);
        self::assertInstanceOf(ContainerInterface::class, $container);

        $client = $container->get(HttpClient::class);

        self::assertInstanceOf(CachingHttpClient::class, $client);
    }

    public function testLoggerAliasResolvesToTraceableLoggerInDev(): void
    {
        // Mirror of the HttpClient regression: the PSR-3 LoggerInterface
        // contract must always be satisfiable with zero config, and in
        // dev the alias must point at the profiler-feeding decorator.
        $router = Relayer::boot($this->projectRoot);

        $property = new ReflectionProperty(AppRouter::class, 'container');
        $container = $property->getValue($router);
        self::assertInstanceOf(ContainerInterface::class, $container);

        self::assertInstanceOf(TraceableLogger::class, $container->get(LoggerInterface::class));
    }

    public function testLoggerAliasResolvesToMonologInProd(): void
    {
        // Force prod via the .env itself rather than $_ENV: once an
        // earlier test has loaded APP_ENV, Symfony tracks it in
        // SYMFONY_DOTENV_VARS and re-applies the .env value over a
        // plain $_ENV assignment, so only the file is order-independent.
        \file_put_contents($this->projectRoot . '/.env', "APP_ENV=prod\n");

        $router = Relayer::boot($this->projectRoot);

        $property = new ReflectionProperty(AppRouter::class, 'container');
        $container = $property->getValue($router);
        self::assertInstanceOf(ContainerInterface::class, $container);

        // In dev the alias resolves to TraceableLogger (not a Monolog
        // Logger), so asserting the concrete Monolog Logger here proves
        // prod is unwrapped — the decorator is skipped entirely.
        self::assertInstanceOf(Logger::class, $container->get(LoggerInterface::class));
    }

    public function testBootWithoutEnvFileDoesNotFail(): void
    {
        \unlink($this->projectRoot . '/.env');

        $router = Relayer::boot($this->projectRoot);

        self::assertInstanceOf(AppRouter::class, $router);
    }

    public function testBootAutoLoadsServicesYaml(): void
    {
        \mkdir($this->projectRoot . '/config', 0o755, true);
        \file_put_contents(
            $this->projectRoot . '/config/services.yaml',
            <<<'YAML'
            services:
              _defaults:
                autowire: true
                public: true

              Polidog\Relayer\Tests\Fixtures\PlainService: ~
              Polidog\Relayer\Tests\Fixtures\ServiceWithDependency: ~
            YAML,
        );

        $configurator = new class($this->projectRoot) extends AppConfigurator {
            public ?ContainerBuilder $captured = null;

            public function configure(ContainerBuilder $container): void
            {
                $this->captured = $container;
            }
        };

        $router = Relayer::boot($this->projectRoot, $configurator);

        self::assertInstanceOf(AppRouter::class, $router);
        self::assertNotNull($configurator->captured);
        self::assertTrue($configurator->captured->hasDefinition(Fixtures\ServiceWithDependency::class));
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
            \is_dir($path) ? $this->rrmdir($path) : \unlink($path);
        }
        \rmdir($dir);
    }
}
