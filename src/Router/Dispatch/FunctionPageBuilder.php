<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Closure;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\UsePhp\Runtime\Element;
use RuntimeException;

/**
 * Turn a function-style page factory (the closure returned from a
 * `page.psx` / `page.php`) into a {@see FunctionPage}.
 *
 * The factory itself is autowired via {@see FactoryArgumentResolver} and may
 * return either of two shapes:
 *  - **Two-level**: factory returns a render `Closure`. Standard pattern when
 *    the page needs to declare cache policy / metadata / etc. before the
 *    render body executes.
 *  - **Single-level shorthand**: factory IS the render — it returned an
 *    {@see Element} directly. Re-wrapped in a no-op closure so the same
 *    FunctionPage contract works downstream.
 */
final class FunctionPageBuilder
{
    public function __construct(
        private readonly FactoryArgumentResolver $argumentResolver,
        private readonly AuthenticatorLocator $authenticatorLocator,
        private readonly PageIdentifier $pageIdentifier,
    ) {}

    /**
     * @param array<string, string> $params
     */
    public function build(Closure $factory, string $pagePath, array $params, ?Request $currentRequest): FunctionPage
    {
        $pageId = $this->pageIdentifier->pageId($pagePath);
        $context = new PageContext($params, $pageId);
        $context->setAuthenticator($this->authenticatorLocator->resolve());
        $args = $this->argumentResolver->resolve($factory, $context, $pagePath, $currentRequest);
        $result = $factory(...$args);

        if ($result instanceof Closure) {
            $renderFn = $result;
        } elseif ($result instanceof Element) {
            $renderFn = static fn (): Element => $result;
        } else {
            throw new RuntimeException("Page factory must return a Closure or Element: {$pagePath}");
        }

        $pageClass = FunctionPage::class;

        return new $pageClass($renderFn, $context, $pageId);
    }
}
