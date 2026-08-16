<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router;

use Exception;

/**
 * Internal control-flow signal: "the response for this request is already
 * complete — unwind to {@see AppRouter::run()} and return".
 *
 * Raised on the two short-circuit branches that used to call `exit`: the
 * 304 answer to a conditional GET and the POST-Redirect-Get hop after a
 * `useState` action. Both send their headers and then have nothing left to
 * render, but they sit several frames deep in the dispatch stack.
 *
 * Why not `exit`: under FrankenPHP's worker mode `exit` terminates the
 * *worker script*, not just the request, and FrankenPHP restarts it — so
 * every 304 and every form POST would throw away the booted application.
 * Unwinding with an exception keeps the worker alive, and lets
 * `AppRouter::run()`'s `finally` do the per-request cleanup that PHP skips
 * on `exit`.
 *
 * Not part of the public API: it is caught by `run()` and never escapes to
 * user code. The one thing user code must not do is swallow it — a root
 * `middleware.php` that wraps `$next(...)` in `catch (\Throwable)` will
 * turn these short-circuits into an empty 200. Catch narrow exception
 * types there, or re-throw this one.
 *
 * @internal
 */
final class StopRequest extends Exception {}
