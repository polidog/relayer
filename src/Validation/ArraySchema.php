<?php

declare(strict_types=1);

namespace Polidog\Relayer\Validation;

final class ArraySchema extends Schema
{
    public function __construct(private readonly Schema $element) {}

    public function min(int $count, ?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_array($v) && \count($v) >= $count,
            $message ?? \sprintf('Must contain at least %d item%s.', $count, 1 === $count ? '' : 's'),
        );
    }

    public function max(int $count, ?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_array($v) && \count($v) <= $count,
            $message ?? \sprintf('Must contain at most %d item%s.', $count, 1 === $count ? '' : 's'),
        );
    }

    public function nonEmpty(?string $message = null): static
    {
        return $this->min(1, $message ?? 'Must not be empty.');
    }

    protected function parseDefined(mixed $input, string $path, array &$errors): mixed
    {
        if (!\is_array($input)) {
            $errors[$path] = 'Must be an array.';

            return null;
        }

        /** @var list<mixed> $result */
        $result = [];

        $index = 0;
        foreach ($input as $value) {
            $childPath = '' === $path ? (string) $index : $path . '.' . $index;
            $parsed = $this->element->parseAtPath($value, $childPath, $errors);
            $result[] = $parsed;
            ++$index;
        }

        return $result;
    }
}
