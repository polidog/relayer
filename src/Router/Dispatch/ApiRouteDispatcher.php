<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use Polidog\Relayer\Auth\AuthGuard;
use Polidog\Relayer\Auth\AuthorizationException;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\Response;
use Polidog\Relayer\Profiler\Profiler;
use Polidog\Relayer\Router\Api\RouteHandlers;
use Polidog\Relayer\Router\Component\PageContext;
use Polidog\Relayer\Router\HttpException;
use Polidog\Relayer\Router\Routing\RouteMatch;
use RuntimeException;

/**
 * Dispatches API routes (`route.php`).
 *
 * @internal
 */
final readonly class ApiRouteDispatcher
{
    public function __construct(
        private PageLoader $pages,
        private HttpMessages $messages,
        private ?Profiler $profiler,
    ) {}

    /**
     * Dispatch an API route (`route.php`). The file returns a method-keyed
     * map of handler closures; the one matching the request method is
     * autowired with the SAME resolver function-style pages use — so
     * `PageContext`, `Request`, `Identity`, and container services inject
     * identically, and `$ctx->requireAuth()` / `$ctx->redirect()` work
     * because this runs inside the dispatch's Authorization/Redirect catch.
     *
     * The handler must return a {@see Response} (built via
     * `Response::json()` / `text()` / `noContent()` / `redirect()`) — the
     * one explicit output contract; returning anything else is a server bug,
     * logged and answered like any uncaught exception (a JSON `500`).
     *
     * `OPTIONS` and `HEAD` are synthesized when not declared explicitly, to
     * match Next.js: an undeclared `OPTIONS` → `204` + `Allow`, an
     * undeclared `HEAD` runs the `GET` handler and drops the body. An
     * explicit handler for either always wins. No declared handler for the
     * method → `405` + `Allow` (JSON body).
     *
     * Auth failures are translated to a JSON `401` / `403` here rather than
     * the page path's HTML-login `302`: an API client wants a status code,
     * not a redirect to a form. `$ctx->abort()` / `notFound()` is likewise
     * translated to a JSON error with the exception's status here, so an API
     * route never emits the HTML error page. A handler that calls
     * `$ctx->redirect()` still produces a `Location` response — that is a
     * deliberate, content-type-neutral handler action, not an error gate, so
     * it bubbles to the dispatch unchanged.
     */
    public function dispatch(RouteMatch $match, Request $request): void
    {
        $this->profiler?->collect('route', 'api', [
            'pattern' => $match->route->pattern,
            'method' => $request->method,
            'params' => $match->getParams(),
            'routePath' => $match->getPagePath(),
        ]);

        $file = $match->getPagePath();

        if (!\file_exists($file)) {
            // Scanned but gone by dispatch (deleted mid-process). Keep the
            // API surface JSON instead of falling back to the HTML 404.
            Response::json(['error' => $this->messages->tr('relayer.http.404', 'Not Found')], 404)->send();

            return;
        }

        $handlers = RouteHandlers::fromFile($file);

        $method = $request->method;

        $handler = $handlers->handlerFor($method);

        // Auto `OPTIONS`: no user code runs (Next.js parity) — just advertise
        // what the route answers. An explicit `OPTIONS` handler skips this.
        if (null === $handler && 'OPTIONS' === $method) {
            Response::noContent(204)
                ->withHeader('Allow', \implode(', ', $handlers->effectiveAllowedMethods()))
                ->send()
            ;

            return;
        }

        // Auto `HEAD`: run the `GET` handler, then strip the body. An
        // explicit `HEAD` handler skips this and owns its own response.
        $omitBody = false;
        if (null === $handler && 'HEAD' === $method) {
            $handler = $handlers->handlerFor('GET');
            $omitBody = true;
        }

        if (null === $handler) {
            Response::json(['error' => $this->messages->tr('relayer.http.405', 'Method Not Allowed')], 405)
                ->withHeader('Allow', \implode(', ', $handlers->effectiveAllowedMethods()))
                ->send()
            ;

            return;
        }

        $context = new PageContext($match->getParams(), $this->pages->computePageId($file));
        $context->setAuthenticator($this->pages->resolveAuthenticator());

        // A non-nullable `Identity` parameter throws during argument
        // resolution; `$ctx->requireAuth()` throws inside the handler.
        // Both land here and become a JSON 401/403 instead of the dispatch's
        // HTML-login redirect.
        try {
            $args = $this->pages->resolveFactoryArguments($handler, $context, $file, $request);
            $result = $handler(...$args);
        } catch (AuthorizationException $exception) {
            $status = AuthGuard::DECISION_FORBIDDEN === $exception->decision ? 403 : 401;
            $error = 403 === $status
                ? $this->messages->tr('relayer.http.403', 'Forbidden')
                : $this->messages->tr('relayer.http.401', 'Unauthorized');
            $response = Response::json(['error' => $error], $status);
            ($omitBody ? $response->withoutBody() : $response)->send();

            return;
        } catch (HttpException $exception) {
            // $ctx->abort()/notFound() from an API handler: keep the API
            // surface JSON instead of letting it bubble to run() and render
            // the HTML error page (same API/HTML boundary the
            // AuthorizationException translation above maintains).
            $response = Response::json(['error' => $this->messages->localizedReason($exception)], $exception->status);
            ($omitBody ? $response->withoutBody() : $response)->send();

            return;
        }

        if (!$result instanceof Response) {
            throw new RuntimeException(\sprintf(
                'API route %s handler for "%s" must return a %s '
                . '(use Response::json(...) / text() / noContent() / redirect()); %s returned.',
                $file,
                $method,
                Response::class,
                \get_debug_type($result),
            ));
        }

        ($omitBody ? $result->withoutBody() : $result)->send();
    }
}
