<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Component;

use Closure;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\Relayer\Router\Form\ActionInterface;
use Polidog\Relayer\Router\Form\CsrfToken;
use Polidog\Relayer\Router\Form\FormAction;
use Polidog\Relayer\Router\RedirectException;
use Polidog\UsePhp\Runtime\Element;
use Psr\Container\ContainerInterface;
use RuntimeException;

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
        PageContext::setCurrent(null);
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

    #[RunInSeparateProcess]
    public function testHandlerRedirectUnwindsAsRedirectException(): void
    {
        $context = new PageContext([], '/users');
        $token = $context->action('save', static function () use ($context): void {
            $context->redirect('/users');
        });

        $page = $this->makePage($context, '/users');

        $_POST = [
            '_usephp_action' => $token,
            '_usephp_csrf' => CsrfToken::getToken(),
        ];

        try {
            $page->dispatchActionFromRequest();
            self::fail('RedirectException should unwind out of the dispatcher');
        } catch (RedirectException $exception) {
            self::assertSame('/users', $exception->location);
            self::assertSame(303, $exception->status);
        }
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

    #[RunInSeparateProcess]
    public function testHasPendingActionReturnsTrueWhenActionNotYetRegistered(): void
    {
        // Token targets this page and names an action that has NOT been
        // registered yet — this is the sub-component self-registration case
        // where a pre-render pass is required.
        $context = new PageContext([], '/users');
        $page = $this->makePage($context, '/users');

        $_POST = [
            '_usephp_action' => FormAction::createForPage('/users', 'save'),
            '_usephp_csrf' => CsrfToken::getToken(),
        ];

        self::assertTrue($page->hasPendingAction());
    }

    public function testHasPendingActionReturnsFalseWhenActionAlreadyRegistered(): void
    {
        // Factory-registered actions are available before dispatch — no
        // pre-render pass needed, so hasPendingAction() must return false.
        $context = new PageContext([], '/users');
        $token = $context->action('save', static function (): void {});
        $page = $this->makePage($context, '/users');

        $_POST = ['_usephp_action' => $token];

        self::assertFalse($page->hasPendingAction());
    }

    public function testHasPendingActionReturnsFalseOnGet(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $context = new PageContext([], '/users');
        $token = $context->action('save', static function (): void {});
        $page = $this->makePage($context, '/users');

        $_POST = ['_usephp_action' => $token];

        self::assertFalse($page->hasPendingAction());
    }

    public function testHasPendingActionReturnsFalseForDifferentPage(): void
    {
        $context = new PageContext([], '/users');
        $page = $this->makePage($context, '/users');

        $_POST = ['_usephp_action' => FormAction::createForPage('/other', 'save')];

        self::assertFalse($page->hasPendingAction());
    }

    public function testHasPendingActionReturnsFalseWithoutToken(): void
    {
        $page = $this->makePage(new PageContext([], '/users'), '/users');
        $_POST = [];

        self::assertFalse($page->hasPendingAction());
    }

    public function testHasPendingActionReturnsFalseWhenNameMissing(): void
    {
        $page = $this->makePage(new PageContext([], '/users'), '/users');

        // Class-style token has no 'name' field — must not trigger pre-render.
        $_POST = ['_usephp_action' => FormAction::create('App\SomePage', 'handle')];

        self::assertFalse($page->hasPendingAction());
    }

    public function testRenderAfterDispatchClearsRenderStateAndReRenders(): void
    {
        $callCount = 0;
        $context = new PageContext([], '/users');
        PageContext::setCurrent($context);

        $renderFn = static function () use ($context, &$callCount): Element {
            ++$callCount;
            $context->action('save', static function (): void {});
            $context->js('/app.js');

            return new Element('div', [], []);
        };

        $page = $this->makePage($context, '/users', $renderFn);

        $page->render();
        $page->renderAfterDispatch(); // clears actions + scripts, then re-renders

        self::assertSame(2, $callCount);
        // scripts should contain exactly one entry from the final render, not two
        self::assertCount(1, $context->getScripts());
    }

    public function testHasPendingActionReturnsFalseForDiClassToken(): void
    {
        // DI-dispatched actions never need a pre-render pass — the handler
        // is resolved from the container at dispatch time.
        $context = new PageContext([], '/users');
        $page = $this->makePageWithContainer($context, '/users', $this->makeContainer([]));

        $_POST = ['_usephp_action' => FormAction::createDiActionForPage('/users', 'App\SomeAction')];

        self::assertFalse($page->hasPendingAction());
    }

    #[RunInSeparateProcess]
    public function testDispatchInvokesDiHandlerFromContainer(): void
    {
        $captured = null;
        $handler = new class($captured) implements ActionInterface {
            public function __construct(public mixed &$captured) {}

            public function handle(array $form): void
            {
                $this->captured = $form;
            }
        };

        $container = $this->makeContainer([$handler::class => $handler]);
        $context = new PageContext([], '/users');
        $page = $this->makePageWithContainer($context, '/users', $container);

        $_POST = [
            '_usephp_action' => FormAction::createDiActionForPage('/users', $handler::class),
            '_usephp_csrf' => CsrfToken::getToken(),
            'email' => 'alice@example.com',
        ];

        $page->dispatchActionFromRequest();

        self::assertSame(['email' => 'alice@example.com'], $captured);
    }

    private function makePage(PageContext $context, string $pageId, ?Closure $renderFn = null): FunctionPage
    {
        $renderFn ??= static fn () => new Element('div', [], []);

        return new FunctionPage($renderFn, $context, $pageId);
    }

    private function makePageWithContainer(
        PageContext $context,
        string $pageId,
        ContainerInterface $container,
        ?Closure $renderFn = null,
    ): FunctionPage {
        $renderFn ??= static fn () => new Element('div', [], []);

        return new FunctionPage($renderFn, $context, $pageId, $container);
    }

    /**
     * @param array<string, mixed> $services
     */
    private function makeContainer(array $services): ContainerInterface
    {
        return new class($services) implements ContainerInterface {
            /** @param array<string, mixed> $services */
            public function __construct(private array $services) {}

            public function get(string $id): mixed
            {
                return $this->services[$id] ?? throw new RuntimeException("Not found: {$id}");
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };
    }
}
