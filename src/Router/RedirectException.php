<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router;

use RuntimeException;

/**
 * Thrown by `PageContext::redirect()` (typically from inside a form-action
 * handler registered via `$ctx->action(...)`). The framework catches this in
 * AppRouter and emits a single `Location` header instead of rendering the
 * page — handler code just calls `$ctx->redirect('/path')` and lets the
 * exception unwind, exactly like `requireAuth()` does for auth failures.
 *
 * Defaults to 303 See Other: after a POST form action the browser must
 * re-issue the follow-up request as GET (Post/Redirect/Get).
 */
final class RedirectException extends RuntimeException
{
    public function __construct(
        public readonly string $location,
        public readonly int $status = 303,
    ) {
        parent::__construct("Redirect to {$location} ({$status})");
    }
}
