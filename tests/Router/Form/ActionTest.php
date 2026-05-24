<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Form;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\Relayer\Router\Form\Action;
use Polidog\Relayer\Router\Form\ActionInterface;
use Polidog\Relayer\Router\Form\FormAction;

final class ActionTest extends TestCase
{
    protected function setUp(): void
    {
        PageContext::setCurrent(null);
    }

    protected function tearDown(): void
    {
        PageContext::setCurrent(null);
    }

    public function testCreateRegistersHandlerAndReturnsPageScopedToken(): void
    {
        $ctx = new PageContext([], '/todos');
        PageContext::setCurrent($ctx);

        $called = false;
        $token = Action::create('addTodo', static function () use (&$called): void {
            $called = true;
        });

        self::assertTrue(FormAction::isToken($token));

        $decoded = FormAction::decode($token);
        self::assertNotNull($decoded);
        self::assertSame('/todos', $decoded['page']);
        self::assertSame('addTodo', $decoded['name']);

        $handler = $ctx->getAction('addTodo');
        self::assertNotNull($handler);
        $handler();
        self::assertTrue($called);
    }

    public function testCreateAcceptsInvokableObject(): void
    {
        $ctx = new PageContext([], '/todos');
        PageContext::setCurrent($ctx);

        $invokable = new class {
            public bool $called = false;

            public function __invoke(): void
            {
                $this->called = true;
            }
        };

        Action::create('doSomething', $invokable);

        $handler = $ctx->getAction('doSomething');
        self::assertNotNull($handler);
        $handler();
        self::assertTrue($invokable->called);
    }

    public function testCreatePassesThroughArgs(): void
    {
        $ctx = new PageContext([], '/todos');
        PageContext::setCurrent($ctx);

        $token = Action::create('edit', static function (): void {}, ['id' => 42]);

        $decoded = FormAction::decode($token);
        self::assertNotNull($decoded);
        self::assertSame(['id' => 42], $decoded['args']);
    }

    public function testRegisterEmbedsDiClassInToken(): void
    {
        $ctx = new PageContext([], '/todos');
        PageContext::setCurrent($ctx);

        $handler = new class implements ActionInterface {
            public function handle(array $form): void {}
        };

        $token = Action::register($handler);

        $decoded = FormAction::decode($token);
        self::assertNotNull($decoded);
        self::assertSame('/todos', $decoded['page']);
        self::assertSame($handler::class, $decoded['di_class']);
        // Class-based actions are NOT registered in the PageContext registry —
        // the container resolves them at dispatch time.
        self::assertNull($ctx->getAction($handler::class));
    }
}
