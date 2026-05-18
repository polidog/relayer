<?php

declare(strict_types=1);

namespace Polidog\Relayer\Auth\Token;

use Polidog\Relayer\Auth\NativeSession;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\InjectorContainer;

/**
 * The current request's raw `Authorization` header.
 *
 * A deliberately tiny seam. {@see TokenAuthenticator} is resolved through
 * the Symfony container (see {@see InjectorContainer}'s
 * authenticator lookup), which — unlike page autowiring — cannot hand it
 * the per-request {@see Request}. So the header is
 * read through this interface instead, exactly the way
 * {@see NativeSession} reaches `$_SESSION`: the
 * default implementation touches a superglobal, and tests substitute an
 * in-memory value.
 */
interface AuthorizationHeader
{
    /**
     * The raw header value (e.g. `Bearer eyJ…`), or null when no
     * `Authorization` header was sent.
     */
    public function value(): ?string;
}
