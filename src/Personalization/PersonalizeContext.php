<?php

declare(strict_types=1);

namespace Polidog\Relayer\Personalization;

use Polidog\Relayer\Auth\AuthenticatorInterface;
use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Http\Cache;

/**
 * Slim per-request context handed to a personalization handler factory.
 *
 * Mirrors a subset of {@see \Polidog\Relayer\Router\Component\PageContext}:
 * the handler can read the current principal, expose route-style params (none
 * are populated today; reserved for future `/{id}/{param}` shapes), and
 * declare a `Cache` override. Form actions, metadata, and the `#[Auth]`
 * gate live on the full PageContext because they don't apply to a
 * fragment endpoint that bypasses layouts and the HTML document.
 */
final class PersonalizeContext
{
    private ?Cache $cache = null;
    private ?AuthenticatorInterface $authenticator = null;

    /**
     * @param array<string, string> $params
     * @param string                $id     The personalize id (e.g. "user-header")
     */
    public function __construct(
        public readonly array $params = [],
        public readonly string $id = '',
    ) {}

    /**
     * @internal AppRouter wires this so `$ctx->user()` works without the
     *           handler depending on AuthenticatorInterface directly.
     */
    public function setAuthenticator(?AuthenticatorInterface $authenticator): void
    {
        $this->authenticator = $authenticator;
    }

    public function user(): ?Identity
    {
        return $this->authenticator?->user();
    }

    /**
     * Override the default `Cache-Control: private, no-store` policy. The
     * framework will assert the supplied `Cache` is safe for a personalized
     * response (rejects `public: true` / `sMaxAge`) before emitting.
     */
    public function cache(Cache $cache): void
    {
        $this->cache = $cache;
    }

    public function getCache(): ?Cache
    {
        return $this->cache;
    }
}
