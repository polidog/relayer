<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Routing;

interface RouterInterface
{
    public function match(string $path): ?RouteMatch;

    public function getErrorPagePath(): ?string;
}
