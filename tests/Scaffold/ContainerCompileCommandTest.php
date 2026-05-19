<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Scaffold;

use Closure;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\FileEtagStore;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Scaffold\ContainerCompileCommand;
use Polidog\Relayer\Scaffold\InitCommand;
use ReflectionObject;
use Symfony\Component\DependencyInjection\ContainerInterface as SymfonyContainerInterface;

final class ContainerCompileCommandTest extends TestCase
{
    private string $project;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->project = \sys_get_temp_dir() . '/relayer-container-' . \bin2hex(\random_bytes(6));
        \mkdir($this->project, 0o755, true);
        $this->lines = [];
    }

    protected function tearDown(): void
    {
        self::removeTree($this->project);
    }

    // Isolated: it `require`s the generated class from a temp path, and
    // each test compiles to a *different* temp dir under the same class
    // name — a shared process would hit "cannot redeclare".
    #[RunInSeparateProcess]
    public function testCompilesAndDumpsAWorkingContainer(): void
    {
        $status = ContainerCompileCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());
        self::assertStringContainsString(
            'Compiled DI container to ' . Relayer::COMPILED_CONTAINER_FILE,
            $this->captured(),
        );
        // No App\AppConfigurator in a bare project — framework defaults.
        self::assertStringContainsString('framework-default container', $this->captured());

        $artifact = $this->project . '/' . Relayer::COMPILED_CONTAINER_FILE;
        self::assertFileExists($artifact);

        $php = (string) \file_get_contents($artifact);
        // A full parse; raises ParseError on malformed dumped source.
        self::assertNotSame([], \token_get_all($php, \TOKEN_PARSE));

        // The dump must load into the exact class prod boot instantiates
        // and behave as a Symfony container. Guard the require so a second
        // test in the same process can't redeclare it.
        $class = Relayer::COMPILED_CONTAINER_CLASS;
        if (!\class_exists($class, false)) {
            require $artifact;
        }
        self::assertTrue(\class_exists($class, false));

        $container = new $class();
        self::assertInstanceOf(SymfonyContainerInterface::class, $container);
        // A service registerDefaults() always binds — proves the dump
        // carries the framework wiring, not just an empty shell.
        self::assertTrue($container->has(FileEtagStore::class));
    }

    public function testInvalidServicesConfigFailsTheCompileAtDeployTime(): void
    {
        \mkdir($this->project . '/config', 0o755, true);
        \file_put_contents(
            $this->project . '/config/services.yaml',
            "services:\n  broken: [unclosed\n",
        );

        $status = ContainerCompileCommand::run([], $this->capture(), $this->project);

        self::assertSame(1, $status);
        self::assertStringContainsString('Could not compile the container', $this->captured());
        self::assertFileDoesNotExist($this->project . '/' . Relayer::COMPILED_CONTAINER_FILE);
    }

    public function testReachableThroughTheRelayerCliDispatch(): void
    {
        $status = InitCommand::run(['container:compile'], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());
        self::assertStringContainsString('Compiled DI container', $this->captured());
        self::assertFileExists($this->project . '/' . Relayer::COMPILED_CONTAINER_FILE);
    }

    public function testCliUsageAdvertisesContainerCompile(): void
    {
        $status = InitCommand::run(['help'], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString('relayer container:compile', $this->captured());
    }

    /**
     * The payoff path: with a dump present and APP_ENV not dev,
     * Relayer::boot() must wrap the dumped container instead of
     * rebuilding via ContainerFactory. Isolated because it `require`s the
     * generated class and touches process env.
     */
    #[RunInSeparateProcess]
    public function testProdBootLoadsTheDumpedContainerInsteadOfRebuilding(): void
    {
        self::assertSame(0, ContainerCompileCommand::run([], $this->capture(), $this->project), $this->captured());

        // Force prod resolution (Relayer::isDev reads these).
        unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
        \putenv('APP_ENV');

        $router = Relayer::boot($this->project);
        self::assertInstanceOf(AppRouter::class, $router);

        $psr = (new ReflectionObject($router))->getProperty('container')->getValue($router);
        self::assertIsObject($psr);
        $wrapped = (new ReflectionObject($psr))->getProperty('container')->getValue($psr);

        self::assertIsObject($wrapped);
        self::assertSame(
            Relayer::COMPILED_CONTAINER_CLASS,
            $wrapped::class,
            'prod boot rebuilt the container instead of loading the dump',
        );
    }

    private function capture(): Closure
    {
        return function (string $line): void {
            $this->lines[] = $line;
        };
    }

    private function captured(): string
    {
        return \implode("\n", $this->lines);
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
