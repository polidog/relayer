<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Fixtures;

/**
 * Minimal fixture for env-placeholder tests: takes a string ctor arg so a
 * services.yaml binding using `%env(VAR)%` can be observed end-to-end.
 */
final class ServiceWithStringArg
{
    public function __construct(public readonly string $value) {}
}
