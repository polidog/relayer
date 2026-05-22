<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Di;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Di\ContainerFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Convention-config loading from `config/`: env-aware `when@<env>` blocks
 * and per-env `services.{env}.*` override files.
 *
 * The loaders {@see ContainerFactory::loadConventionConfigs()} builds are
 * env-aware. `<env>` is Relayer's two-valued model — `dev` when `$isDev`,
 * `prod` otherwise — so `when@dev` / `services.dev.*` apply on a dev build
 * and are inert on a prod build (and vice versa). These tests pin both
 * halves against {@see ContainerFactory::create()} directly (null compiled
 * file ⇒ live build).
 */
final class ContainerFactoryConventionConfigTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = \sys_get_temp_dir() . '/relayer-conv-' . \bin2hex(\random_bytes(6));
        \mkdir($this->projectRoot . '/config', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->projectRoot);
    }

    #[DataProvider('provideWhenEnvBlockIsAppliedPerBuildCases')]
    public function testWhenEnvBlockIsAppliedPerBuild(bool $isDev, string $expected): void
    {
        $this->writeConfig('services.yaml', <<<'YAML'
            parameters:
              conv.base: 'base-value'

            when@dev:
              parameters:
                conv.env: 'dev-block'

            when@prod:
              parameters:
                conv.env: 'prod-block'
            YAML);

        $container = $this->build($isDev);

        self::assertSame('base-value', $container->getParameter('conv.base'), 'top-level parameters load regardless of env');
        self::assertSame(
            $expected,
            $container->getParameter('conv.env'),
            'only the when@<env> block matching the build env may apply',
        );
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function provideWhenEnvBlockIsAppliedPerBuildCases(): iterable
    {
        yield 'dev build applies when@dev' => [true, 'dev-block'];

        yield 'prod build applies when@prod' => [false, 'prod-block'];
    }

    public function testEnvSpecificYamlOverridesBaseOnDevBuild(): void
    {
        $this->writeConfig('services.yaml', <<<'YAML'
            parameters:
              conv.value: 'from-base'
              conv.base_only: 'kept'
            YAML);
        $this->writeConfig('services.dev.yaml', <<<'YAML'
            parameters:
              conv.value: 'from-dev-file'
            YAML);

        $container = $this->build(true);

        self::assertSame('from-dev-file', $container->getParameter('conv.value'), 'services.dev.yaml overrides the base file');
        self::assertSame('kept', $container->getParameter('conv.base_only'), 'base parameters not touched by the env file survive');
    }

    public function testEnvSpecificYamlIsIgnoredOnTheOtherEnvBuild(): void
    {
        $this->writeConfig('services.yaml', <<<'YAML'
            parameters:
              conv.value: 'from-base'
            YAML);
        $this->writeConfig('services.dev.yaml', <<<'YAML'
            parameters:
              conv.value: 'from-dev-file'
            YAML);

        $container = $this->build(false);

        self::assertSame('from-base', $container->getParameter('conv.value'), 'services.dev.yaml must not load on a prod build');
    }

    public function testEnvSpecificPhpOverridesBaseOnProdBuild(): void
    {
        $this->writeConfig('services.php', <<<'PHP'
            <?php

            use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

            return static function (ContainerConfigurator $configurator): void {
                $configurator->parameters()->set('conv.value', 'from-base-php');
            };
            PHP);
        $this->writeConfig('services.prod.php', <<<'PHP'
            <?php

            use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

            return static function (ContainerConfigurator $configurator): void {
                $configurator->parameters()->set('conv.value', 'from-prod-php');
            };
            PHP);

        $container = $this->build(false);

        self::assertSame('from-prod-php', $container->getParameter('conv.value'), 'services.prod.php overrides services.php on a prod build');
    }

    private function build(bool $isDev): ContainerInterface
    {
        return ContainerFactory::create($this->projectRoot, null, $isDev);
    }

    private function writeConfig(string $name, string $contents): void
    {
        \file_put_contents($this->projectRoot . '/config/' . $name, $contents);
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
