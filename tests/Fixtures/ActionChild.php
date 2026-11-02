<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Fixtures;

use Polidog\Relayer\Router\Component\PageContext;
use Polidog\Rosanna\Runtime\Element;
use Polidog\Rosanna\Runtime\Renderable;

/**
 * A `Renderable` child that registers its own server action from inside
 * `render()` through `PageContext::current()`, and counts how often it is
 * rendered and dispatched.
 */
final class ActionChild implements Renderable
{
    /** @var list<array{string, array<array-key, mixed>}> */
    public static array $dispatched = [];

    public static int $renders = 0;

    /**
     * @param array<mixed> $children rendered after this child's form
     */
    public function __construct(
        private readonly string $name,
        private readonly array $children = [],
        private readonly ?string $redirectTo = null,
    ) {}

    public function render(): Element
    {
        ++self::$renders;

        $ctx = PageContext::current();
        $name = $this->name;
        $redirectTo = $this->redirectTo;

        $token = $ctx->action($name, static function (array $form) use ($ctx, $name, $redirectTo): void {
            self::$dispatched[] = [$name, $form];

            if (null !== $redirectTo) {
                $ctx->redirect($redirectTo);
            }
        });

        return new Element('Fragment', [], [
            new Element('form', ['action' => $token, 'data-child' => $name], [
                'dispatched:' . \count(self::$dispatched),
            ]),
            ...$this->children,
        ]);
    }
}
