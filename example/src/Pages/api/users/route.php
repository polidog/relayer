<?php

declare(strict_types=1);

// JSON API route — the counterpart of the HTML page at /users.
//
// `route.php` returns a method-keyed map of handler closures. Each closure
// is autowired exactly like a function-style page factory: declare a typed
// parameter and the framework injects it (here `UserRepository` straight
// from the DI container). The handler returns a `Response` — the one
// explicit output contract; `Response::json()` encodes + sets the header.
// An unlisted method gets 405 + an `Allow` header; OPTIONS / HEAD are
// synthesized automatically.

use App\Service\UserRepository;
use Polidog\Relayer\Http\Response;

return [
    'GET' => static fn (UserRepository $users): Response => Response::json(['users' => $users->all()]),
];
