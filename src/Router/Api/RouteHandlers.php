<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Api;

use Closure;
use RuntimeException;

/**
 * The method → handler map declared by a `route.php` file.
 *
 * An API route file returns an array keyed by HTTP method, each value a
 * closure that is autowired and invoked exactly like a function-style
 * page factory:
 *
 *   // src/Pages/api/users/route.php
 *   return [
 *       'GET'  => fn (UserRepository $repo) => $repo->all(),
 *       'POST' => function (Request $req, UserRepository $repo) {
 *           $repo->create($req->allPost());
 *           return ['ok' => true];
 *       },
 *   ];
 *
 * Keys are normalized to upper-case, so `'get'` and `'GET'` are the same
 * handler. The file must contain no class/function declarations — only the
 * returned map — because it is `require`d fresh on every dispatch (the
 * returned value is essential and must not be cached away by `require_once`).
 */
final class RouteHandlers
{
    /**
     * @param array<string, Closure> $handlers method (upper-case) => handler
     */
    private function __construct(
        private readonly array $handlers,
    ) {}

    /**
     * `require` $file and validate it returned a non-empty method map.
     *
     * @throws RuntimeException when the file does not return a non-empty
     *                          array of `METHOD => Closure` pairs
     */
    public static function fromFile(string $file): self
    {
        $returned = require $file;

        if (!\is_array($returned) || [] === $returned) {
            throw new RuntimeException(\sprintf(
                'API route %s must return a non-empty array mapping HTTP methods '
                . "to closures, e.g. ['GET' => fn () => [...]].",
                $file,
            ));
        }

        $handlers = [];
        foreach ($returned as $method => $handler) {
            if (!\is_string($method) || '' === $method) {
                throw new RuntimeException(\sprintf(
                    'API route %s has a non-string method key. Keys must be HTTP '
                    . "method names, e.g. 'GET', 'POST'.",
                    $file,
                ));
            }
            if (!$handler instanceof Closure) {
                throw new RuntimeException(\sprintf(
                    'API route %s handler for "%s" must be a Closure, %s given.',
                    $file,
                    $method,
                    \get_debug_type($handler),
                ));
            }
            $handlers[\strtoupper($method)] = $handler;
        }

        return new self($handlers);
    }

    public function handlerFor(string $method): ?Closure
    {
        return $this->handlers[\strtoupper($method)] ?? null;
    }

    /**
     * Methods this route serves, sorted — used for the `Allow` header on a
     * 405 response.
     *
     * @return list<string>
     */
    public function allowedMethods(): array
    {
        $methods = \array_keys($this->handlers);
        \sort($methods);

        return $methods;
    }
}
