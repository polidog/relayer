<?php

declare(strict_types=1);

use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\Response;

return [
    'GET' => static fn (): Response => Response::json(['pong' => true]),
    'post' => static fn (Request $req): Response => Response::json(['echo' => $req->post('msg')]),
];
