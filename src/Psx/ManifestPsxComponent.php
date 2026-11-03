<?php

declare(strict_types=1);

namespace Polidog\Relayer\Psx;

use Closure;
use Polidog\Rosanna\Runtime\AcceptsArgumentResolver;
use Polidog\Rosanna\Runtime\PsxArgumentResolver;
use RuntimeException;

/**
 * A compiled PSX component of an app without a container, loaded the way
 * Rosanna's own PsxRenderer would but through {@see CompiledPsxFile}.
 *
 * Rosanna's loader requires the file itself, which runs it a second time when
 * compiling in the same process has executed it to read its Defer
 * declaration, and again on every boot of a worker. The arguments are still
 * those of the resolver given (Rosanna's default one unless the app passed its
 * own), from the manifest's parameters, as Rosanna's loader would do.
 *
 * @internal
 */
final class ManifestPsxComponent
{
    private AcceptsArgumentResolver|Closure|null $component = null;

    /**
     * @param array<mixed> $parameters the manifest entry's `parameters`
     */
    public function __construct(
        private readonly string $fqcn,
        private readonly string $file,
        private readonly array $parameters,
        private readonly PsxArgumentResolver $resolver,
    ) {}

    /**
     * @param array<string, mixed> $props
     */
    public function __invoke(array $props = []): mixed
    {
        $component = $this->component ??= $this->load();

        // One that resolves its own arguments (a deferred component, only at
        // its endpoint) has been handed the resolver.
        return $component instanceof AcceptsArgumentResolver
            ? $component($props)
            : $component($props, ...$this->resolve($props));
    }

    private function load(): AcceptsArgumentResolver|Closure
    {
        $loaded = CompiledPsxFile::load($this->file);

        if ($loaded instanceof AcceptsArgumentResolver) {
            return $loaded->withArgumentResolver($this->resolve(...));
        }

        if (!\is_callable($loaded)) {
            throw new RuntimeException("PSX file did not return a callable: {$this->file}. Run `vendor/bin/rosanna-defer compile` to regenerate.");
        }

        return Closure::fromCallable($loaded);
    }

    /**
     * @param array<string, mixed> $props
     *
     * @return list<mixed> the arguments after `$props`
     */
    private function resolve(array $props): array
    {
        return $this->resolver->resolve($this->fqcn, $this->parameters, $props);
    }
}
