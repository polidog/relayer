<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Component;

use Closure;
use InvalidArgumentException;
use Polidog\Relayer\Http\Cache;
use Polidog\Relayer\Router\Form\FormAction;

final class PageContext
{
    /** @var array<string, string> */
    private array $metadata = [];

    private ?Cache $cache = null;

    /** @var array<string, Closure> */
    private array $actions = [];

    /**
     * @param array<string, string> $params
     * @param string                $pageId route-derived page id used to scope
     *                                      function-style server actions (so a
     *                                      token resolves back to the same page
     *                                      factory on the dispatching request)
     */
    public function __construct(
        public readonly array $params = [],
        public readonly string $pageId = '',
    ) {}

    /**
     * @param array<string, string> $metadata
     */
    public function metadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }

    /**
     * @return array<string, string>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Declare an HTTP cache policy for this page from inside a function-style
     * factory. Used by the framework to emit `Cache-Control` / `ETag` etc. and
     * short-circuit with `304 Not Modified` when the request's conditional
     * headers indicate the client already has a fresh copy.
     *
     * Class-style pages should use the `#[Cache]` attribute instead.
     */
    public function cache(Cache $cache): void
    {
        $this->cache = $cache;
    }

    public function getCache(): ?Cache
    {
        return $this->cache;
    }

    /**
     * Register a server action — a closure that is invoked when a form
     * submitted with the returned token reaches this page. The factory
     * closure of a function-style page is re-executed on every request, so
     * the action table is rebuilt before dispatch and the token only needs
     * to encode `(pageId, name)`.
     *
     * @param array<string, mixed> $args
     */
    public function action(string $name, Closure $handler, array $args = []): string
    {
        if (isset($this->actions[$name])) {
            throw new InvalidArgumentException(
                \sprintf('Action "%s" is already registered on page "%s".', $name, $this->pageId),
            );
        }

        $this->actions[$name] = $handler;

        return FormAction::createForPage($this->pageId, $name, $args);
    }

    /**
     * @internal
     */
    public function getAction(string $name): ?Closure
    {
        return $this->actions[$name] ?? null;
    }
}
