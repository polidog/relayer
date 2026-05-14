<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Personalization;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Personalization\Fragment;
use Polidog\UsePhp\Runtime\Element;

final class FragmentTest extends TestCase
{
    public function testPlaceholderReturnsElementWithDataAttrsAndFallback(): void
    {
        $fallback = new Element('a', ['href' => '/login'], ['Login']);
        $element = Fragment::placeholder(id: 'user-header', fallback: $fallback);

        self::assertSame('div', $element->type);
        self::assertSame('user-header', $element->props['data-relayer-personalize']);
        self::assertSame(
            '/_relayer/personalize/user-header',
            $element->props['data-relayer-endpoint'],
        );
        self::assertSame([$fallback], $element->children);
    }

    public function testPlaceholderWithStringFallbackIsAccepted(): void
    {
        $element = Fragment::placeholder(id: 'badge', fallback: 'Loading...');

        self::assertSame(['Loading...'], $element->children);
    }

    public function testPlaceholderWithoutFallbackHasNoChildren(): void
    {
        $element = Fragment::placeholder(id: 'badge');

        self::assertSame([], $element->children);
    }

    public function testPlaceholderCustomTagIsHonored(): void
    {
        $element = Fragment::placeholder(id: 'badge', tag: 'span');

        self::assertSame('span', $element->type);
    }

    public function testInvalidIdRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Fragment::placeholder(id: 'bad/id');
    }

    public function testIdWithTraversalRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Fragment::placeholder(id: '../etc/passwd');
    }

    public function testIsValidIdAcceptsAllowedShape(): void
    {
        self::assertTrue(Fragment::isValidId('user-header'));
        self::assertTrue(Fragment::isValidId('cart_summary'));
        self::assertTrue(Fragment::isValidId('a.b.c'));
        self::assertTrue(Fragment::isValidId('A1'));
    }

    public function testIsValidIdRejectsEmptyAndBadShape(): void
    {
        self::assertFalse(Fragment::isValidId(''));
        self::assertFalse(Fragment::isValidId('with space'));
        self::assertFalse(Fragment::isValidId('slash/in/id'));
        self::assertFalse(Fragment::isValidId('..'));
    }
}
