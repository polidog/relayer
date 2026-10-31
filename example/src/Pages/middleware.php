<?php

declare(strict_types=1);

use Polidog\Relayer\Http\Cors;
use Polidog\Relayer\Http\OriginGuard;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\TrustedProxy;
use Polidog\Relayer\Relayer;
use Psr\Log\LoggerInterface;

// Root middleware wraps every page/route dispatch. The contract is a
// single closure `fn(Request, $next)`; there is no chain runner, so to
// run several things you compose closures by hand. Here, outermost
// first:
//
//   1. TrustedProxy — resolve the real client IP from `Fly-Client-IP`
//      (exposed as `$request->clientIp()`; `ip()` stays REMOTE_ADDR).
//   2. OriginGuard  — reject requests that bypassed the CDN. Off until
//      ORIGIN_SECRET is set (`.env.local` locally, `fly secrets set` on
//      Fly), so this example keeps working on a bare `php -S`.
//   3. request-id header + the provided CORS middleware (which answers
//      OPTIONS preflights itself and otherwise continues to the route).
$proxy = TrustedProxy::middleware(['preset' => 'fly']);

$guardMode = $_ENV['ORIGIN_GUARD_MODE'] ?? $_SERVER['ORIGIN_GUARD_MODE'] ?? getenv('ORIGIN_GUARD_MODE');
$guard = OriginGuard::middleware([
    'header' => 'X-Origin-Secret',
    'secretsFromEnv' => ['ORIGIN_SECRET', 'ORIGIN_SECRET_PREVIOUS'],
    'except' => ['/healthz'],
    'mode' => 'report' === $guardMode ? 'report' : 'enforce',
    'logger' => Relayer::container()->get(LoggerInterface::class),
]);

$cors = Cors::middleware([
    'origins' => ['*'],
    'methods' => ['GET', 'POST', 'OPTIONS'],
]);

$app = function (Request $request, Closure $next) use ($cors): void {
    if (!headers_sent()) {
        header('X-Request-Id: ' . bin2hex(random_bytes(8)));
    }

    $cors($request, $next);
};

return fn (Request $request, Closure $next) => $proxy(
    $request,
    fn (Request $r) => $guard($r, fn (Request $r) => $app($r, $next)),
);
