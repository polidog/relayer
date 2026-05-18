<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Scaffold;

use Closure;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Scaffold\InitCommand;
use Polidog\Relayer\Scaffold\RoutesCompileCommand;

final class RoutesCompileCommandTest extends TestCase
{
    private string $project;

    /** @var list<string> */
    private array $lines = [];

    protected function setUp(): void
    {
        $this->project = \sys_get_temp_dir() . '/relayer-compile-' . \bin2hex(\random_bytes(6));
        \mkdir($this->project . '/src/Pages/(marketing)/about', 0o755, true);
        \mkdir($this->project . '/src/Pages/blog/[slug]', 0o755, true);
        $this->lines = [];

        \file_put_contents(
            $this->project . '/src/Pages/page.php',
            "<?php\n\nreturn static fn () => 'home';\n",
        );
        \file_put_contents(
            $this->project . '/src/Pages/(marketing)/about/page.php',
            "<?php\n\nreturn static fn () => 'about';\n",
        );
        \file_put_contents(
            $this->project . '/src/Pages/blog/[slug]/page.php',
            "<?php\n\nreturn static fn () => 'post';\n",
        );
    }

    protected function tearDown(): void
    {
        self::removeTree($this->project);
    }

    public function testCompilesAndWritesThePortableArtifact(): void
    {
        $status = RoutesCompileCommand::run([], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString(
            'Compiled 3 routes to ' . Relayer::COMPILED_ROUTES_FILE,
            $this->captured(),
        );

        $artifact = $this->project . '/' . Relayer::COMPILED_ROUTES_FILE;
        self::assertFileExists($artifact);

        $php = (string) \file_get_contents($artifact);
        self::assertStringContainsString('AUTO-GENERATED', $php);
        // Route group stripped from the URL, path kept relative.
        self::assertStringContainsString("'pattern' => '/about'", $php);
        self::assertStringContainsString("'pagePath' => '(marketing)/about/page.php'", $php);
        self::assertStringNotContainsString($this->project, $php);

        $data = require $artifact;
        self::assertIsArray($data);
        self::assertCount(3, $data);
    }

    public function testMissingSrcPagesReturnsError(): void
    {
        $bare = \sys_get_temp_dir() . '/relayer-compile-bare-' . \bin2hex(\random_bytes(6));
        \mkdir($bare, 0o755, true);

        try {
            $status = RoutesCompileCommand::run([], $this->capture(), $bare);

            self::assertSame(1, $status);
            self::assertStringContainsString('No src/Pages directory', $this->captured());
        } finally {
            self::removeTree($bare);
        }
    }

    public function testRouteGroupCollisionFailsTheCompileAtDeployTime(): void
    {
        // (a)/about and (b)/about both collapse to /about — must fail the
        // compile rather than write an ambiguous artifact.
        \mkdir($this->project . '/src/Pages/(a)/about', 0o755, true);
        \mkdir($this->project . '/src/Pages/(b)/about', 0o755, true);
        \file_put_contents(
            $this->project . '/src/Pages/(a)/about/page.php',
            "<?php\n\nreturn static fn () => 'a';\n",
        );
        \file_put_contents(
            $this->project . '/src/Pages/(b)/about/page.php',
            "<?php\n\nreturn static fn () => 'b';\n",
        );

        $status = RoutesCompileCommand::run([], $this->capture(), $this->project);

        self::assertSame(1, $status);
        self::assertStringContainsString('Could not compile routes', $this->captured());
        self::assertFileDoesNotExist($this->project . '/' . Relayer::COMPILED_ROUTES_FILE);
    }

    public function testReachableThroughTheRelayerCliDispatch(): void
    {
        $status = InitCommand::run(['routes:compile'], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString('Compiled 3 routes', $this->captured());
    }

    public function testCliUsageAdvertisesRoutesCompile(): void
    {
        $status = InitCommand::run(['help'], $this->capture(), $this->project);

        self::assertSame(0, $status);
        self::assertStringContainsString('relayer routes:compile', $this->captured());
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
