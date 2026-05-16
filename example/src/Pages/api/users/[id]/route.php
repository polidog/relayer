<?php

declare(strict_types=1);

// Dynamic API route — JSON counterpart of /users/[id].
//
// The `[id]` segment is captured into `$ctx->params` (same convention as
// pages). When the user is missing we set the status code directly and
// return an error body: there is no Response object in this minimal
// contract — a handler-set status simply passes through, which is the
// escape hatch for error responses.

use App\Service\UserRepository;
use Polidog\Relayer\Router\Component\PageContext;

return [
    'GET' => static function (PageContext $ctx, UserRepository $users): array {
        $id = (int) ($ctx->params['id'] ?? '0');
        $user = $users->find($id);

        if (null === $user) {
            \http_response_code(404);

            return ['error' => "No user with id {$id}"];
        }

        return ['user' => $user];
    },
];
