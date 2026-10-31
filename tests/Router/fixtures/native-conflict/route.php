<?php

declare(strict_types=1);

use Polidog\Relayer\Http\Response;

return ['GET' => static fn (): Response => Response::json(['php' => true])];
