<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Native;

use Polidog\Relayer\Http\Request;

/**
 * Runs a built native handler (`route.go` / `middleware.go`) for one
 * request and hands back its response.
 *
 * {@see NativeDispatcher} and {@see NativeProxy} depend on this rather than
 * on how the handler is reached, so the transport can change without
 * touching the dispatch rules around it (the not-built / stale checks, the
 * continue protocol, the JSON 500s). {@see CgiRunner} — a process per
 * request — is the one implementation today.
 *
 * Anything the framework hands the handler that is not part of the
 * request itself (`RELAYER_ROUTE_PARAMS`, `RELAYER_MATCHED_PATTERN`, …)
 * arrives as `$extra`. A runner must make sure no client input can
 * override those names.
 */
interface NativeRunner
{
    /**
     * @param string                $binary the built handler, as `relayer native:build` wrote it
     * @param array<string, string> $extra  framework-owned variables for the handler
     *
     * @throws NativeProcessException when the handler could not produce a response
     *                                (did not start, failed, timed out, answered
     *                                something that is not a response)
     */
    public function run(string $binary, Request $request, array $extra = []): NativeRun;
}
