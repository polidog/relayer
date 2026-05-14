<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Component;

use Closure;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\Relayer\Router\Form\CsrfToken;
use Polidog\Relayer\Router\Form\FormAction;
use Polidog\UsePhp\Runtime\Element;

/**
 * Dispatch tests exercise CsrfToken which calls session_start(), so each
 * test runs in its own process to avoid leaking session/header state.
 */
final class FunctionPageActionDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($_SERVER['REQUEST_METHOD']);
    }

    #[RunInSeparateProcess]
    public function testDispatchInvokesMatchingActionWithFormData(): void
    {
        $captured = null;

        $context = new PageContext([], '/users');
        $token = $context->action('save', static function (array $form) use (&$captured): void {
            $captured = $form;
        });

        $page = $this->makePage($context, '/users');

        $_POST = [
            '_usephp_action' => $token,
            '_usephp_csrf' => CsrfToken::getToken(),
            'name' => 'alice',
        ];

        $page->dispatchActionFromRequest();

        self::assertSame(['name' => 'alice'], $captured);
    }

    #[RunInSeparateProcess]
    public function testDispatchReturns403OnInvalidCsrf(): void
    {
        $called = false;

        $context = new PageContext([], '/users');
        $token = $context->action('save', static function () use (&$called): void {
            $called = true;
        });

        $page = $this->makePage($context, '/users');

        // Initialize the session so CsrfToken::validate() compares against a
        // real expected value instead of short-circuiting on "no token yet".
        CsrfToken::getToken();

        $_POST = [
            '_usephp_action' => $token,
            '_usephp_csrf' => 'bogus',
        ];

        $page->dispatchActionFromRequest();

        self::assertFalse($called);
        self::assertSame(403, \http_response_code());
    }

    public function testDispatchSkipsTokenForDifferentPage(): void
    {
        $called = false;

        $context = new PageContext([], '/users');
        $context->action('save', static function () use (&$called): void {
            $called = true;
        });

        $page = $this->makePage($context, '/users');

        $_POST = [
            '_usephp_action' => FormAction::createForPage('/other', 'save'),
            '_usephp_csrf' => 'irrelevant',
        ];

        $page->dispatchActionFromRequest();

        self::assertFalse($called);
    }

    public function testDispatchIgnoresClassScopedToken(): void
    {
        $called = false;

        $context = new PageContext([], '/users');
        $context->action('save', static function () use (&$called): void {
            $called = true;
        });

        $page = $this->makePage($context, '/users');

        // Tokens produced by PageComponent::action() must not be picked up by
        // the function-page dispatcher.
        $_POST = [
            '_usephp_action' => FormAction::create('App\SomePage', 'save'),
            '_usephp_csrf' => 'irrelevant',
        ];

        $page->dispatchActionFromRequest();

        self::assertFalse($called);
    }

    public function testDispatchIsNoOpForGet(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $called = false;

        $context = new PageContext([], '/users');
        $token = $context->action('save', static function () use (&$called): void {
            $called = true;
        });

        $page = $this->makePage($context, '/users');

        $_POST = ['_usephp_action' => $token];

        $page->dispatchActionFromRequest();

        self::assertFalse($called);
    }

    private function makePage(PageContext $context, string $pageId, ?Closure $renderFn = null): FunctionPage
    {
        $renderFn ??= static fn () => new Element('div', [], []);
        $pageClass = FunctionPage::class;

        return new $pageClass($renderFn, $context, $pageId);
    }
}
