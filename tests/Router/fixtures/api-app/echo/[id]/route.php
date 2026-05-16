<?php

declare(strict_types=1);

use Polidog\Relayer\Http\Response;
use Polidog\Relayer\Router\Component\PageContext;

return [
    'GET' => static fn (PageContext $ctx): Response => Response::json(['id' => $ctx->params['id'] ?? null]),
];
