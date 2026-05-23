<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Form;

use Closure;
use Polidog\Relayer\Router\Component\PageContext;

/**
 * Static factory for registering a server action on the current page without
 * referencing PageContext directly. Sub-components call this from inside their
 * render methods to self-register their form handlers:
 *
 *   $token = Action::create('addTodo', fn(array $form) => $repo->add($form));
 *
 * The returned token is a signed string that encodes the page id and action
 * name; pass it to a hidden `_usephp_action` field or to FormAction::toHidden().
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
}
