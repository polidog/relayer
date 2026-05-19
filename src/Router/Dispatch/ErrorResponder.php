<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Polidog\Relayer\Auth\AuthGuard;
use Polidog\Relayer\Auth\AuthorizationException;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Component\ErrorPageComponent;
use Polidog\Relayer\Router\Document\DocumentInterface;
use Polidog\Relayer\Router\HttpException;
use Polidog\Relayer\Router\Layout\LayoutStack;
use Polidog\Relayer\Router\RedirectException;
use Polidog\Relayer\Router\Routing\RouterInterface;

/**
 * Translate AppRouter dispatch failures into HTTP responses:
 * {@see AuthorizationException} → 302 / 401 / 403 (HTML side),
 * {@see RedirectException} → `Location` with the handler's chosen status,
 * {@see HttpException} (including the 404 path) → the project's `error.psx`
 * wrapped in the root layout, or the built-in error document fallback.
 *
 * Extracted from {@see AppRouter} so the error /
 * auth response policy lives in one place separate from the dispatch
 * orchestration.
 */
final class ErrorResponder
{
    public function __construct(
        private readonly DocumentInterface $document,
        private readonly ComponentLoader $componentLoader,
        private readonly PageRenderer $pageRenderer,
        private readonly FrameworkTranslator $translator,
        private readonly RouterInterface $router,
        private readonly string $appDirectory,
    ) {}

    public function notFound(): void
    {
        $this->errorPage(404, $this->translator->trans('relayer.http.page_not_found', 'Page not found'));
    }

    /**
     * Convert an {@see AuthorizationException} (raised by
     * `$ctx->requireAuth()` or by a non-nullable `Identity` parameter on an
     * anonymous request) into the same 302 / 401 / 403 response the
     * class-style `#[Auth]` attribute produces.
     */
    public function authorizationFailure(AuthorizationException $exception, ?Request $currentRequest): void
    {
        if (\headers_sent()) {
            return;
        }

        switch ($exception->decision) {
            case AuthGuard::DECISION_UNAUTHORIZED:
                \http_response_code(401);

                return;

            case AuthGuard::DECISION_FORBIDDEN:
                \http_response_code(403);

                return;

            case AuthGuard::DECISION_REDIRECT:
            default:
                $location = $exception->redirectTo;
                $requestUri = $currentRequest?->path;
                if (null !== $requestUri && '' !== $requestUri && !\str_contains($location, '?')) {
                    $location .= '?next=' . \rawurlencode($requestUri);
                }
                \header('Location: ' . $location, true, 302);

                return;
        }
    }

    /**
     * Emit the `Location` response for a {@see RedirectException} raised by
     * `$ctx->redirect()` (typically from a form-action handler). Unlike the
     * auth redirect, the target is taken verbatim — the handler chose it
     * deliberately, so no `?next=` is appended.
     */
    public function redirect(RedirectException $exception): void
    {
        if (\headers_sent()) {
            return;
        }

        \header('Location: ' . $exception->location, true, $exception->status);
    }

    /**
     * Translate an arbitrary {@see HttpException} into an error page render.
     * 404 is routed back through {@see notFound()} so the single overridable
     * 404 path stays unified (the dev profiler hooks it there) — this means
     * a 404 always renders the standard not-found page/message and does NOT
     * surface a custom `HttpException` reason. That is intentional and
     * lossless: the public `notFound()` / `abort()` APIs expose no custom-
     * message parameter. Every other status goes straight to the shared
     * error renderer with its standard reason phrase.
     */
    public function httpException(HttpException $exception): void
    {
        if (404 === $exception->status) {
            $this->notFound();

            return;
        }

        $this->errorPage($exception->status, $this->translator->localizedReason($exception));
    }

    /**
     * The shared error path: set the status, then render the project's
     * `error.psx` (wrapped in the root layout, receiving the status/message
     * via {@see ErrorPageComponent}) or
     * fall back to the built-in error document. This is the only place the
     * page side touches `http_response_code()` — `abort()` keeps it out of
     * user code.
     */
    public function errorPage(int $status, string $message): void
    {
        \http_response_code($status);

        $errorPagePath = $this->router->getErrorPagePath();

        if (null !== $errorPagePath) {
            $errorComponent = $this->componentLoader->loadErrorPage($errorPagePath, $status, $message);

            if (null !== $errorComponent) {
                $rootLayoutPath = $this->componentLoader->findRootLayoutPath($this->appDirectory);
                $layoutStack = new LayoutStack();

                if (null !== $rootLayoutPath) {
                    $layout = $this->componentLoader->loadLayout($rootLayoutPath, []);
                    if (null !== $layout) {
                        $layoutStack->push($layout);
                    }
                }

                $this->pageRenderer->render($errorComponent, $layoutStack, []);

                return;
            }
        }

        echo $this->document->renderError($status, $message);
    }
}
