<?php

declare(strict_types=1);

namespace Polidog\Relayer\Psx;

use Closure;
use Polidog\Relayer\Profiler\Profiler;
use Polidog\Rosanna\Defer\Deferred;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionFunction;
use ReflectionNamedType;
use ReflectionParameter;
use RuntimeException;
use Throwable;

/**
 * A compiled PSX component whose parameters are answered from the container.
 *
 * Rosanna's PsxArgumentResolver cannot do this: it always passes `$props` first
 * and only supplies the arguments after it, and the manifest records neither a
 * service's nullability nor a default as a value. So the component is called
 * here, from the parameters of the callable its file returned. Services are
 * fetched on every call, since some of them belong to the current request.
 */
final class ContainerPsxComponent
{
    private ?Closure $component = null;

    public function __construct(
        private readonly string $fqcn,
        private readonly string $file,
        private readonly ContainerInterface $container,
    ) {}

    /**
     * @param array<string, mixed> $props
     */
    public function __invoke(array $props = []): mixed
    {
        return ($this->component ??= $this->load())($props);
    }

    /**
     * @return Closure(array<string, mixed>): mixed
     */
    private function load(): Closure
    {
        $loaded = CompiledPsxFile::load($this->file);

        if ($loaded instanceof Deferred) {
            // Deferred also passes `$props` first, and only at the endpoint: on
            // a page it stays a placeholder and no service is asked for.
            return new Deferred($this->reportingFailures($this->withArguments($loaded->inner), $loaded->defer->name), $loaded->defer)(...);
        }

        if (!\is_callable($loaded)) {
            throw new RuntimeException("PSX file did not return a callable: {$this->file}");
        }

        return $this->withArguments(Closure::fromCallable($loaded));
    }

    /**
     * Rosanna's defer endpoint turns a failure into a bare 500 and writes it to
     * PHP's error log only, out of the app's logger. Reported here, where the
     * fragment renders, then left to the endpoint to answer as before.
     *
     * @param Closure(array<array-key, mixed>): mixed $render
     *
     * @return Closure(array<array-key, mixed>): mixed
     */
    private function reportingFailures(Closure $render, string $name): Closure
    {
        return function (array $props) use ($render, $name): mixed {
            try {
                return $render($props);
            } catch (Throwable $exception) {
                $this->report($exception, $name);

                throw $exception;
            }
        };
    }

    private function report(Throwable $exception, string $name): void
    {
        try {
            $logger = $this->container->has(LoggerInterface::class) ? $this->container->get(LoggerInterface::class) : null;
            if ($logger instanceof LoggerInterface) {
                $logger->error(\sprintf("Deferred component '%s' (%s) failed: %s: %s", $name, $this->fqcn, $exception::class, $exception->getMessage()), [
                    'exception' => $exception,
                    'trace' => $exception->getTraceAsString(),
                ]);
            }

            $profiler = $this->container->has(Profiler::class) ? $this->container->get(Profiler::class) : null;
            if ($profiler instanceof Profiler) {
                $profiler->collect('error', 'exception', [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => $exception->getTraceAsString(),
                    'deferred' => $name,
                ]);
            }
        } catch (Throwable) {
            // Reporting must not change the response; Rosanna's error log
            // entry still records the failure.
        }
    }

    /**
     * @return Closure(array<array-key, mixed>): mixed
     */
    private function withArguments(Closure $component): Closure
    {
        $parameters = new ReflectionFunction($component)->getParameters();

        return function (array $props) use ($component, $parameters): mixed {
            $arguments = [];
            foreach ($parameters as $parameter) {
                $arguments[] = $this->argument($parameter, $props);
            }

            return $component(...$arguments);
        };
    }

    /**
     * @param array<array-key, mixed> $props
     */
    private function argument(ReflectionParameter $parameter, array $props): mixed
    {
        $type = $parameter->getType();
        $isNamed = $type instanceof ReflectionNamedType;

        if (0 === $parameter->getPosition() && (null === $type || ($isNamed && 'array' === $type->getName()))) {
            return $props;
        }

        if ($isNamed && !$type->isBuiltin() && $this->container->has($type->getName())) {
            return $this->container->get($type->getName());
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($parameter->allowsNull()) {
            return null;
        }

        throw new RuntimeException(\sprintf(
            'Cannot autowire PSX component parameter $%s of %s: expected a container service, default value, or nullable parameter.',
            $parameter->getName(),
            $this->fqcn,
        ));
    }
}
