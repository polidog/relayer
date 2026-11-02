<?php

declare(strict_types=1);

namespace Polidog\Relayer\Psx;

use Closure;
use Polidog\Rosanna\Defer\Deferred;
use Polidog\Rosanna\Runtime\PsxArgumentResolver;
use Polidog\Rosanna\Runtime\PsxRenderer;
use Psr\Container\ContainerInterface;
use ReflectionFunction;
use ReflectionParameter;
use RuntimeException;

/**
 * Supplies a PSX component's arguments after `$props` from the container.
 *
 * The manifest names each parameter's service type, which is enough for the
 * common case. It records neither whether a service parameter is nullable nor
 * a default as a value, so a parameter the container cannot supply is answered
 * from the compiled component itself.
 */
final class ContainerPsxArgumentResolver implements PsxArgumentResolver
{
    /** @var array<string, list<ReflectionParameter>> */
    private array $reflected = [];

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly string $manifestPath,
    ) {}

    public function resolve(string $fqcn, array $metadata, array $props): array
    {
        $arguments = [];

        foreach (\array_slice($metadata, 1) as $offset => $parameter) {
            $service = \is_array($parameter) && 'service' === ($parameter['kind'] ?? null) ? ($parameter['service'] ?? null) : null;

            $arguments[] = \is_string($service) && $this->container->has($service)
                ? $this->container->get($service)
                : $this->fallback($fqcn, $offset + 1);
        }

        return $arguments;
    }

    private function fallback(string $fqcn, int $position): mixed
    {
        $parameter = ($this->reflected[$fqcn] ??= $this->reflect($fqcn))[$position]
            ?? throw new RuntimeException("PSX component {$fqcn} has no parameter at position {$position}: its manifest is stale. Recompile the components.");

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new RuntimeException(\sprintf(
            'Cannot autowire PSX component parameter $%s of %s: expected a container service, default value, or nullable parameter.',
            $parameter->getName(),
            $fqcn,
        ));
    }

    /**
     * @return list<ReflectionParameter>
     */
    private function reflect(string $fqcn): array
    {
        $entry = PsxRenderer::readArrayFile($this->manifestPath)[$fqcn] ?? null;
        $file = \is_array($entry) ? ($entry['file'] ?? null) : null;
        if (!\is_string($file)) {
            throw new RuntimeException("PSX component {$fqcn} is not in {$this->manifestPath}.");
        }

        $component = (static fn (string $__file): mixed => require $__file)($file);
        if ($component instanceof Deferred) {
            $component = $component->inner;
        }
        if (!\is_callable($component)) {
            throw new RuntimeException("PSX file did not return a callable: {$file}");
        }

        return new ReflectionFunction(Closure::fromCallable($component))->getParameters();
    }
}
