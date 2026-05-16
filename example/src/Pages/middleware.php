<?php

declare(strict_types=1);

use Polidog\Relayer\Http\Cors;
use Polidog\Relayer\Http\Request;

// Root middleware wraps every page/route dispatch. The contract is a
// single closure `fn(Request, $next)`; there is no chain runner, so to
// run several things you compose closures by hand — here: tag the
// response with a request id, then delegate to the provided CORS
// middleware (which answers OPTIONS preflights itself and otherwise
// continues to the route).
$cors = Cors::middleware([
    'origins' => ['*'],
    'methods' => ['GET', 'POST', 'OPTIONS'],
]);

return function (Request $request, Closure $next) use ($cors): void {
    if (!headers_sent()) {
        header('X-Request-Id: ' . bin2hex(random_bytes(8)));
    }

    $cors($request, $next);
};
