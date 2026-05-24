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
 *   // Class-based — component implements ActionInterface or receives it via DI
 *   $token = Action::register($this);          // component is the action
 *   $token = Action::register($this->action);  // component has the action
 *
 * Both return an encoded token (base64 JSON with a prefix) that identifies the
 * page and action name; embed it in a hidden `_usephp_action` field so the
 * framework can dispatch on POST.
 *
 * Delegates to PageContext::current()->action() — only valid during a
 * function-style page request.
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
     * Register a class-based action. The class name is embedded in the
     * returned token; at dispatch time the framework resolves a fresh instance
     * from the DI container and calls handle() — no pre-render pass is needed
     * and no closure is stored in the PageContext.
     */
    public static function register(ActionInterface $handler): string
    {
        return FormAction::createDiActionForPage(
            PageContext::current()->pageId,
            $handler::class,
        );
    }
}
