<?php

declare(strict_types=1);

namespace Polidog\Relayer\Form;

final class FloatSchema extends Schema
{
    public function min(float $value, ?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_float($v) && $v >= $value,
            $message ?? \sprintf('Must be %s or greater.', $value),
        );
    }

    public function max(float $value, ?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_float($v) && $v <= $value,
            $message ?? \sprintf('Must be %s or less.', $value),
        );
    }

    public function positive(?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_float($v) && $v > 0.0,
            $message ?? 'Must be greater than 0.',
        );
    }

    public function nonNegative(?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_float($v) && $v >= 0.0,
            $message ?? 'Must be 0 or greater.',
        );
    }

    protected function isAbsent(mixed $input): bool
    {
        if (null === $input) {
            return true;
        }

        if (\is_string($input) && '' === \trim($input)) {
            return true;
        }

        return false;
    }

    protected function parseDefined(mixed $input, string $path, array &$errors): mixed
    {
        if (\is_float($input)) {
            return $input;
        }

        if (\is_int($input)) {
            return (float) $input;
        }

        if (\is_string($input)) {
            $filtered = \filter_var(\trim($input), \FILTER_VALIDATE_FLOAT);
            if (false !== $filtered) {
                return $filtered;
            }
        }

        $errors[$path] = 'Must be a number.';

        return null;
    }
}
