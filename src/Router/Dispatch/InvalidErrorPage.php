<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Dispatch;

use RuntimeException;

/**
 * The project's `error.psx` exists but cannot be used as an error page: it
 * returns no closure and declares no `Renderable` class, or its closure cannot
 * be called or does not produce an `Element`.
 *
 * A configuration error, so {@see ErrorResponder} lets it surface in dev and,
 * elsewhere, logs it and answers with the built-in error document — the error
 * status stands either way.
 *
 * @internal
 */
final class InvalidErrorPage extends RuntimeException {}
