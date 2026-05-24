<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Form;

use Closure;
use Polidog\Relayer\Router\Component\PageContext;

/**
 * Static factory for registering server actions on the current page without
 * referencing PageContext directly. Sub-components call these methods from
 * inside their render methods to self-register their form handlers.
 *
 * Two patterns:
 *
 *   // Closure / callable
 *   $token = Action::create('addTodo', fn(array $form) => $repo->add($form));
 *
 *   // Class-based — resolved from the DI container at dispatch time;
 *   // no instance is needed at registration time.
 *   $token = Action::register(AddTodoAction::class);
 *   $token = Action::register(self::class);   // when the component is the action
 *
 * Both return an encoded token (base64 JSON with a prefix) that identifies the
 * page and action; embed it in a hidden `_usephp_action` field so the
 * framework can dispatch on POST.
 *
 * Delegates to PageContext::current() — only valid during a function-style
 * page request.
 */
final class Action
{
    /**
     * @param array<string, mixed> $args
     */
    public static function create(string $name, callable $handler, array $args = []): string
    {
        $closure = $handler instanceof Closure ? $handler : Closure::fromCallable($handler);

        return PageContext::current()->action($name, $closure, $args);
    }

    /**
     * Register a class-based action by class name. The class name is embedded
     * in the returned token; at dispatch time the framework resolves a fresh
     * instance from the DI container and calls handle(). No instance is needed
     * at registration time:
     *
     *   $token = Action::register(AddTodoAction::class);
     *
     * CSRF validation protects the dispatch endpoint. Action handlers are
     * responsible for their own authorization checks.
     *
     * @param class-string<ActionInterface> $class
     */
    public static function register(string $class): string
    {
        return FormAction::createDiActionForPage(
            PageContext::current()->pageId,
            $class,
        );
    }
}
