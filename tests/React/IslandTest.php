<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\React;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\React\Island;
use Polidog\UsePhp\Runtime\Element;
use RuntimeException;

final class IslandTest extends TestCase
{
    public function testMountReturnsDivWithNameAndPropsAttributes(): void
    {
        $el = Island::mount('Chart', ['points' => [1, 2, 3], 'label' => 'CPU']);

        self::assertInstanceOf(Element::class, $el);
        self::assertSame('div', $el->type);
        self::assertSame('Chart', $el->props['data-react-island']);
        self::assertSame('{"points":[1,2,3],"label":"CPU"}', $el->props['data-react-props']);
        self::assertSame([], $el->children);
    }

    public function testEmptyPropsSerializeAsObjectNotArray(): void
    {
        $el = Island::mount('Widget');

        // `{}` so the client `{...props}` spread is a no-op; `[]` would be wrong.
        self::assertSame('{}', $el->props['data-react-props']);
    }

    public function testUnicodeAndSlashesLeftUnescaped(): void
    {
        $el = Island::mount('Profile', ['name' => 'こんにちは', 'next' => '/api/users']);

        self::assertSame(
            '{"name":"こんにちは","next":"/api/users"}',
            $el->props['data-react-props'],
        );
    }

    #[DataProvider('provideInvalidNameIsRejectedCases')]
    public function testInvalidNameIsRejected(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('React island name must match');
        Island::mount($name, ['a' => 1]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidNameIsRejectedCases(): iterable
    {
        yield 'empty' => [''];

        yield 'space' => ['My Component'];

        yield 'leading digit' => ['1Chart'];

        yield 'path traversal' => ['../Chart'];

        yield 'dot' => ['ns.Chart'];
    }

    public function testValidNameVariantsAreAccepted(): void
    {
        foreach (['Chart', 'user_card', 'data-grid', '_Internal', 'A1'] as $name) {
            self::assertSame($name, Island::mount($name)->props['data-react-island']);
        }
    }

    public function testUnencodablePropsRaiseActionableError(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('could not be JSON-encoded');
        Island::mount('Chart', ['bad' => \INF]);
    }

    public function testLoaderScriptIsSelfContainedAndDeclaresTheContract(): void
    {
        $script = Island::loaderScript();

        self::assertStringStartsWith('<script>', \trim($script));
        self::assertStringEndsWith('</script>', \trim($script));

        foreach ([
            'window.relayerIslands',
            'register',
            'hydrate',
            'data-react-island',
            'data-react-props',
            'MutationObserver',
        ] as $needle) {
            self::assertStringContainsString($needle, $script);
        }
    }

    public function testLoaderScriptEmitsNonceWhenProvided(): void
    {
        $script = Island::loaderScript('r4nd0m-Nonce_=');

        self::assertStringStartsWith('<script nonce="r4nd0m-Nonce_=">', $script);
        self::assertStringEndsWith('</script>', \trim($script));
        // Same JS body either way — only the opening tag changes.
        self::assertStringContainsString('window.relayerIslands', $script);
    }

    public function testLoaderScriptNonceIsAttributeEscaped(): void
    {
        $script = Island::loaderScript('a"><script>alert(1)</script>');

        self::assertStringContainsString(
            '<script nonce="a&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;">',
            $script,
        );
        self::assertStringNotContainsString('nonce="a"><script>alert(1)', $script);
    }
}
