<?php

declare(strict_types=1);

// JSON API route — the counterpart of the HTML page at /users.
//
// `route.php` returns a method-keyed map of handler closures. Each closure
// is autowired exactly like a function-style page factory: declare a typed
// parameter and the framework injects it (here `UserRepository` straight
// from the DI container). The return value is emitted as JSON; no layout /
// HTML pipeline runs. An unlisted method gets 405 + an `Allow` header.

use App\Service\UserRepository;

return [
    'GET' => static fn (UserRepository $users): array => ['users' => $users->all()],
];
