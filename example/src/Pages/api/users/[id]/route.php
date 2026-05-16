<?php

declare(strict_types=1);

// Dynamic API route — JSON counterpart of /users/[id].
//
// The `[id]` segment is captured into `$ctx->params` (same convention as
// pages). The handler always returns a `Response`: a found user is a
// `Response::json(...)` (200 by default), a missing one is the same
// factory with an explicit 404 status — status and shape are always
// chosen explicitly, there is no raw-data return path.

use App\Service\UserRepository;
use Polidog\Relayer\Http\Response;
use Polidog\Relayer\Router\Component\PageContext;

return [
    'GET' => static function (PageContext $ctx, UserRepository $users): Response {
        $id = (int) ($ctx->params['id'] ?? '0');
        $user = $users->find($id);

        if (null === $user) {
            return Response::json(['error' => "No user with id {$id}"], 404);
        }

        return Response::json(['user' => $user]);
    },
];
