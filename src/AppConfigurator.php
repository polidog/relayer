<?php

declare(strict_types=1);

namespace Polidog\Relayer;

use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Base Symfony DI configurator for usePHP applications.
 *
 * Extend and override `configure()` to register services. The project root is
 * available as `$this->projectRoot` for path-based parameters (config files,
 * cache dirs, etc.).
 *
 * Services registered here participate in autowiring; the framework sets
 * sensible defaults (autowire + public) after `configure()` runs, so most
 * cases only need a bare `register()` call.
 */
class AppConfigurator
{
    public function __construct(protected readonly string $projectRoot)
    {
    }

    public function configure(ContainerBuilder $container): void
    {
        // No default services. Subclasses register their own.
    }
}
