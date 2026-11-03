<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router;

use Exception;

/**
 * Internal control-flow signal: "the response for this request is already
 * complete — unwind to {@see AppRouter::run()} and return".
 *
 * Raised on the short-circuit branches that used to call `exit`: the 304
 * answer to a conditional GET and a class page's `#[Auth]` redirect. They send
 * their headers and then have nothing left to render, but sit several frames
 * deep in the dispatch stack.
 *
 * Why not `exit`: under FrankenPHP's worker mode `exit` terminates the
 * *worker script*, not just the request, and FrankenPHP restarts it — so
 * every 304 and every form POST would throw away the booted application.
 * Unwinding with an exception keeps the worker alive, and lets
 * `AppRouter::run()`'s `finally` do the per-request cleanup that PHP skips
 * on `exit`.
 *
 * Not part of the public API. It is caught where route dispatch hands back
 * to the root middleware, so a `middleware.php` wrapping `$next(...)` in
 * `catch (\Throwable)` never sees it; `run()` catches what is left.
 *
 * @internal
 */
final class StopRequest extends Exception {}
