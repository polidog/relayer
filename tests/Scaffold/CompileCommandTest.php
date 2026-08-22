<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Scaffold;

use Closure;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Scaffold\CompileCommand;
use Polidog\Relayer\Scaffold\InitCommand;

final class CompileCommandTest extends TestCase
{
    private string $project;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->project = \sys_get_temp_dir() . '/relayer-compile-all-' . \bin2hex(\random_bytes(6));
        \mkdir($this->project . '/src/Pages', 0o755, true);
        \mkdir($this->project . '/src/Components', 0o755, true);
        $this->lines = [];

        \file_put_contents(
            $this->project . '/src/Pages/page.php',
            "<?php\n\nreturn static fn () => 'home';\n",
        );
        \file_put_contents(
            $this->project . '/src/Components/Hello.psx',
            <<<'PSX'
                <?php

                declare(strict_types=1);

                namespace App\Components;

                use Polidog\UsePhp\Runtime\Element;

                return function (array $props): Element {
                    return <p>hello</p>;
                };
                PSX,
        );
    }

    protected function tearDown(): void
    {
        self::removeTree($this->project);
    }

    // Isolated for the same reason as ContainerCompileCommandTest: the
    // container step dumps a class under a fixed name per temp project.
    #[RunInSeparateProcess]
    public function testBuildsAllThreeArtifactsInOrder(): void
    {
        $status = CompileCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status, $this->captured());

        $out = $this->captured();
        $psx = (int) \strpos($out, '[1/3]');
        $routes = (int) \strpos($out, '[2/3]');
        $container = (int) \strpos($out, '[3/3]');
        self::assertLessThan($routes, $psx);
        self::assertLessThan($container, $routes);

        self::assertFileExists($this->project . '/var/cache/psx/manifest.php');
        $manifest = require $this->project . '/var/cache/psx/manifest.php';
        self::assertIsArray($manifest);
        self::assertArrayHasKey('App\Components\Hello', $manifest);

        self::assertFileExists($this->project . '/' . Relayer::COMPILED_ROUTES_FILE);
        self::assertStringContainsString('Compiled 1 route to', $out);

        self::assertFileExists($this->project . '/' . Relayer::COMPILED_CONTAINER_FILE);
        self::assertStringContainsString('Compiled DI container to', $out);
    }

    public function testStopsAtTheFirstFailingStep(): void
    {
        // Broken .psx: the PSX step fails, so routes and container must
        // not be attempted or written.
        \file_put_contents(
            $this->project . '/src/Components/Broken.psx',
            "<?php\n\nreturn function (array \$props) {\n    return <div>;\n};\n",
        );

        $status = CompileCommand::run([], $this->capture(), $this->project);

        self::assertNotSame(0, $status);
        self::assertStringNotContainsString('[2/3]', $this->captured());
        self::assertFileDoesNotExist($this->project . '/' . Relayer::COMPILED_ROUTES_FILE);
        self::assertFileDoesNotExist($this->project . '/' . Relayer::COMPILED_CONTAINER_FILE);
    }

    public function testMissingSrcPagesFailsAtTheRoutesStep(): void
    {
        $bare = \sys_get_temp_dir() . '/relayer-compile-all-bare-' . \bin2hex(\random_bytes(6));
        \mkdir($bare, 0o755, true);

        try {
            $status = CompileCommand::run([], $this->capture(), $bare);

            self::assertSame(1, $status);
            self::assertStringContainsString('skipping .psx', $this->captured());
            self::assertStringContainsString('No src/Pages directory', $this->captured());
            self::assertStringNotContainsString('[3/3]', $this->captured());
        } finally {
            self::removeTree($bare);
        }
    }

    public function testCliUsageAdvertisesCompile(): void
    {
        $status = InitCommand::run(['help'], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString('relayer compile ', $this->captured());
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
