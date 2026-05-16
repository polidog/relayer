<?php

declare(strict_types=1);

use Polidog\Relayer\Router\Component\PageContext;

return [
    'GET' => static fn (PageContext $ctx): array => ['id' => $ctx->params['id'] ?? null],
];
