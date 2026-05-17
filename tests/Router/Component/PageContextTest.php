<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Component;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\Relayer\Router\Form\FormAction;
use Polidog\Relayer\Router\HttpException;
use Polidog\Relayer\Router\RedirectException;

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

    public function testGetScriptsDefaultsToEmpty(): void
    {
        self::assertSame([], (new PageContext())->getScripts());
    }

    public function testJsRegistersScriptsInCallOrderWithFlags(): void
    {
        $context = new PageContext();
        $context->js('/a.js');
        $context->js('/b.js', defer: true, module: true);

        $scripts = $context->getScripts();

        self::assertCount(2, $scripts);
        self::assertSame('<script src="/a.js"></script>', $scripts[0]->toHtmlTag());
        self::assertSame('<script src="/b.js" type="module" defer></script>', $scripts[1]->toHtmlTag());
    }

    public function testRedirectThrowsWithDefault303Status(): void
    {
        $context = new PageContext([], '/users');

        try {
            $context->redirect('/users');
        } catch (RedirectException $exception) {
            self::assertSame('/users', $exception->location);
            self::assertSame(303, $exception->status);
        }
    }

    public function testRedirectThrowsWithCustomStatus(): void
    {
        $context = new PageContext([], '/users');

        try {
            $context->redirect('/login', 302);
        } catch (RedirectException $exception) {
            self::assertSame('/login', $exception->location);
            self::assertSame(302, $exception->status);
        }
    }

    public function testNotFoundThrowsHttpExceptionWith404(): void
    {
        $context = new PageContext([], '/users');

        try {
            $context->notFound();
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status);
            self::assertSame('Not Found', $exception->reason);
        }
    }

    public function testAbortThrowsHttpExceptionWithStatusAndReason(): void
    {
        $context = new PageContext([], '/users');

        try {
            $context->abort(403);
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->status);
            self::assertSame('Forbidden', $exception->reason);
        }
    }

    public function testAbortRejectsRedirectStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PageContext())->abort(302);
    }

    public function testAbortRejectsSuccessStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PageContext())->abort(200);
    }
}
