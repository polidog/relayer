<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Fixtures;

final class ServiceWithDependency
{
    public function __construct(public readonly PlainService $inner) {}
}
