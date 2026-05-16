<?php

declare(strict_types=1);

use Polidog\Relayer\Http\Request;

return [
    'GET' => static fn (): array => ['pong' => true],
    'post' => static fn (Request $req): array => ['echo' => $req->post('msg')],
];
