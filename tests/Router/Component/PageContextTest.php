<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Component;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\Relayer\Router\Form\FormAction;

final class PageContextTest extends TestCase
{
    public function testCacheDefaultsToNull(): void
    {
        self::assertNull((new PageContext())->getCache());
    }

    public function testCacheStoresAndExposesPolicy(): void
    {
        $context = new PageContext();
        $cache = new Cache(maxAge: 60, etagKey: 'home');

        $context->cache($cache);

        self::assertSame($cache, $context->getCache());
    }

    public function testActionRegistersClosureAndReturnsPageScopedToken(): void
    {
        $context = new PageContext([], '/users');
        $handler = static function (): void {};

        $token = $context->action('save', $handler);

        self::assertTrue(FormAction::isToken($token));

        $decoded = FormAction::decode($token);
        self::assertNotNull($decoded);
        self::assertSame('/users', $decoded['page']);
        self::assertSame('save', $decoded['name']);

        self::assertSame($handler, $context->getAction('save'));
    }

    public function testActionRejectsDuplicateName(): void
    {
        $context = new PageContext([], '/users');
        $context->action('save', static function (): void {});

        $this->expectException(InvalidArgumentException::class);

        $context->action('save', static function (): void {});
    }

    public function testGetActionReturnsNullForUnknownName(): void
    {
        self::assertNull((new PageContext([], '/'))->getAction('missing'));
    }
}
