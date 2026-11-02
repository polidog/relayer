<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Psx;

use Closure;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Psx\CompiledPsxFile;
use RuntimeException;

/**
 * Files here declare symbols in namespaces of their own, so a test that runs
 * in this process cannot collide with another one.
 */
final class CompiledPsxFileTest extends TestCase
{
    private string $workDir;

    private string $errorLog;

    private string $previousErrorLog;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/relayer-psx-file-' . \bin2hex(\random_bytes(6));
        \mkdir($this->workDir, 0o777, true);
        $this->errorLog = $this->workDir . '/error.log';
        $this->previousErrorLog = (string) \ini_get('error_log');
        \ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        \ini_set('error_log', $this->previousErrorLog);
        \exec('rm -rf ' . \escapeshellarg($this->workDir));
    }

    public function testAChangedFileWithoutDeclarationsIsLoadedAgain(): void
    {
        $file = $this->write('reload.php', 'return static fn (): string => "old";');
        self::assertSame('old', self::call(CompiledPsxFile::load($file)));

        $this->write('reload.php', 'return static fn (): string => "new";');

        self::assertSame('new', self::call(CompiledPsxFile::load($file)));
        self::assertSame('', $this->log());
    }

    #[DataProvider('provideAChangedFileRedeclaringASymbolKeepsTheLoadedValueCases')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAChangedFileRedeclaringASymbolKeepsTheLoadedValue(string $declaration, string $symbol): void
    {
        $file = $this->write('declares.php', "namespace Fixture\\Fn;\n" . \sprintf($declaration, 'v1') . "\nreturn static fn (): string => 'v1';");
        $before = self::call(CompiledPsxFile::load($file));

        $this->write('declares.php', "namespace Fixture\\Fn;\n" . \sprintf($declaration, 'v2') . "\nreturn static fn (): string => 'v2';");
        $after = self::call(CompiledPsxFile::load($file));

        self::assertSame(['v1', 'v1'], [$before, $after]);
        self::assertStringContainsString($file, $this->log());
        self::assertStringContainsString($symbol, $this->log());
        self::assertStringContainsString('Restart', $this->log());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideAChangedFileRedeclaringASymbolKeepsTheLoadedValueCases(): iterable
    {
        yield 'function' => ['function helper(): string { return "%s"; }', 'Fixture\Fn\helper'];

        yield 'class' => ['final class Helper { public const string V = "%s"; }', 'Fixture\Fn\Helper'];

        yield 'interface' => ['interface Helper { public const string V = "%s"; }', 'Fixture\Fn\Helper'];

        yield 'trait' => ['trait Helper { public const string V = "%s"; }', 'Fixture\Fn\Helper'];

        yield 'enum' => ['enum Helper: string { case V = "%s"; }', 'Fixture\Fn\Helper'];

        yield 'function in an unguarded if' => ['if (true) { function helper(): string { return "%s"; } }', 'Fixture\Fn\helper'];

        yield 'class in a bare block' => ['{ final class Helper { public const string V = "%s"; } }', 'Fixture\Fn\Helper'];

        yield 'function in an else' => ['if (false) {} else { function helper(): string { return "%s"; } }', 'Fixture\Fn\helper'];

        yield 'function in a try' => ['try { function helper(): string { return "%s"; } } finally {}', 'Fixture\Fn\helper'];

        yield 'function in a nested block' => ['if (true) { while (true) { { function helper(): string { return "%s"; } } break; } }', 'Fixture\Fn\helper'];

        yield 'function in a switch' => ['switch (1) { case 1: function helper(): string { return "%s"; } }', 'Fixture\Fn\helper'];

        yield 'function in an alternative if' => ['if (true): function helper(): string { return "%s"; } endif;', 'Fixture\Fn\helper'];

        yield 'function after a block holding a closure' => ['if (true) { $c = function () { return 1; }; } function helper(): string { return "%s"; }', 'Fixture\Fn\helper'];

        yield 'class after an anonymous class taking a closure' => ['$o = new class (function () {}) { public function __construct(public \Closure $c) {} }; final class Helper { public const string V = "%s"; }', 'Fixture\Fn\Helper'];

        yield 'function guarded for another name' => ['if (!\function_exists("Fixture\\\Fn\\\other")) { function helper(): string { return "%s"; } }', 'Fixture\Fn\helper'];

        yield 'interface guarded by class_exists' => ['if (!\class_exists("Fixture\\\Fn\\\Helper")) { interface Helper { public const string V = "%s"; } }', 'Fixture\Fn\Helper'];

        yield 'function guarded with a positive check' => ['if (\function_exists("Fixture\\\Fn\\\helper") || true) { function helper(): string { return "%s"; } }', 'Fixture\Fn\helper'];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGuardedDeclarationsDoNotBlockAReload(): void
    {
        $body = <<<'PHP'
            namespace Fixture\Guarded;

            if (!function_exists('Fixture\Guarded\plain')) {
                function plain(): string { return 'p'; }
            }
            if ( ! \function_exists( "\\Fixture\\Guarded\\Qualified" ) ) {
                function qualified(): string { return 'q'; }
            }
            if (!\class_exists('Fixture\Guarded\Helper', false)) {
                final class Helper { public static function v(): string { return 'c'; } }
            }
            if (!\interface_exists('Fixture\Guarded\Contract')) {
                interface Contract {}
            }
            if (!\trait_exists('Fixture\Guarded\Mixin')) {
                trait Mixin {}
            }
            if (!\enum_exists('Fixture\Guarded\Suit')) {
                enum Suit { case Hearts; }
            }

            return static fn (): string => '%s' . plain() . qualified() . Helper::v();
            PHP;

        $file = $this->write('guarded.php', \sprintf($body, 'v1'));
        self::assertSame('v1pqc', self::call(CompiledPsxFile::load($file)));

        $this->write('guarded.php', \sprintf($body, 'v2'));

        self::assertSame('v2pqc', self::call(CompiledPsxFile::load($file)));
        self::assertSame('', $this->log());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testARefusedReloadIsLoggedOnceAndTheServedContentLoadsSilently(): void
    {
        $v1 = "namespace Fixture\\Once;\nfunction helper(): string { return 'v1'; }\nreturn static fn (): string => helper();";
        $file = $this->write('once.php', $v1);
        CompiledPsxFile::load($file);

        $this->write('once.php', "namespace Fixture\\Once;\nfunction helper(): string { return 'v2'; }\nreturn static fn (): string => helper();");
        CompiledPsxFile::load($file);
        CompiledPsxFile::load($file);
        self::assertSame(1, \substr_count($this->log(), 'Restart'));

        // Back to what this process runs: nothing to warn about.
        $this->write('once.php', $v1);
        self::assertSame('v1', self::call(CompiledPsxFile::load($file)));
        self::assertSame(1, \substr_count($this->log(), 'Restart'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAChangedFileDeclaringOnlyNewSymbolsIsLoadedAgain(): void
    {
        $file = $this->write('grows.php', "namespace Fixture\\Grows;\nreturn static fn (): string => 'v1';");
        self::assertSame('v1', self::call(CompiledPsxFile::load($file)));

        $this->write('grows.php', "namespace Fixture\\Grows;\nfunction helper(): string { return 'v2'; }\nreturn static fn (): string => helper();");

        self::assertSame('v2', self::call(CompiledPsxFile::load($file)));
        self::assertSame('', $this->log());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCodeThatOnlyLooksLikeADeclarationDoesNotBlockAReload(): void
    {
        $body = <<<'PHP'
            namespace Fixture\Lookalike;

            use function Polidog\Rosanna\Defer\deferred;
            use Polidog\Rosanna\Defer\{Defer, Deferred};

            if (!\function_exists('Fixture\Lookalike\guarded')) {
                function guarded(): string { return 'g'; }
            }
            $name = Defer::class;
            $anonymous = new class { public function method(): string { return 'm'; } };
            $closure = function () use ($name): string { return $name; };
            $ref = function &(): array { static $a = []; return $a; };
            if (true) {
                $inBlock = static fn (): object => new class { public function inBlock(): void {} };
                $later = function (): void { function declaredWhenCalled(): void {} };
            }
            $keywords = static fn (int $class, int $function): int => $class + $function;
            $named = $keywords(class: 1, function: 2);
            $static = Defer::class . \stdClass::class;
            // function commented() {}
            $text = 'class InAString {}';

            return static fn (): string => '%s' . guarded() . $anonymous->method();
            PHP;

        $file = $this->write('lookalike.php', \sprintf($body, 'v1'));
        self::assertSame('v1gm', self::call(CompiledPsxFile::load($file)));

        $this->write('lookalike.php', \sprintf($body, 'v2'));

        self::assertSame('v2gm', self::call(CompiledPsxFile::load($file)));
        self::assertSame('', $this->log());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDeclarationsInBracedNamespacesAreFound(): void
    {
        $body = "namespace Fixture\\Braced { function helper(): string { return '%s'; } }\nnamespace { return static fn (): string => \\Fixture\\Braced\\helper(); }";

        $file = $this->write('braced.php', \sprintf($body, 'v1'));
        $before = self::call(CompiledPsxFile::load($file));

        $this->write('braced.php', \sprintf($body, 'v2'));
        $after = self::call(CompiledPsxFile::load($file));

        self::assertSame(['v1', 'v1'], [$before, $after]);
        self::assertStringContainsString('Fixture\Braced\helper', $this->log());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAFileThatThrewAfterDeclaringIsNotRunAgain(): void
    {
        $file = $this->write('throws.php', "namespace Fixture\\Throws;\nfunction helper(): void {}\nthrow new \\LogicException('boom');");

        try {
            CompiledPsxFile::load($file);
            self::fail('The first load should throw.');
        } catch (LogicException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Fixture\Throws\helper');

        CompiledPsxFile::load($file);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAChangedFileWithUnbalancedBracesReportsTheParseError(): void
    {
        $file = $this->write('broken.php', "namespace Fixture\\Broken;\nif (true) { function helper(): string { return 'v1'; } }\nreturn static fn (): string => helper();");
        self::assertSame('v1', self::call(CompiledPsxFile::load($file)));

        // PHP rejects it before declaring anything, so there is nothing to protect.
        $this->write('broken.php', "namespace Fixture\\Broken;\nif (true) { function helper(): string { return 'v2'; }\nreturn static fn (): string => helper();");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid PHP');

        CompiledPsxFile::load($file);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAFileThatThrewAfterDeclaringInABlockIsNotRunAgain(): void
    {
        $file = $this->write('throws-block.php', "namespace Fixture\\ThrowsBlock;\nif (true) { function helper(): void {} }\nthrow new \\LogicException('boom');");

        try {
            CompiledPsxFile::load($file);
            self::fail('The first load should throw.');
        } catch (LogicException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Fixture\ThrowsBlock\helper');

        CompiledPsxFile::load($file);
    }

    private function write(string $name, string $body): string
    {
        $path = $this->workDir . '/' . $name;
        \file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\n{$body}\n");

        return $path;
    }

    private function log(): string
    {
        return \is_file($this->errorLog) ? (string) \file_get_contents($this->errorLog) : '';
    }

    private static function call(mixed $value): mixed
    {
        self::assertInstanceOf(Closure::class, $value);

        return $value();
    }
}
