<?php

declare(strict_types=1);

namespace Polidog\Relayer\Validation;

use Polidog\Relayer\I18n\Translators;

final class FloatSchema extends Schema
{
    public function min(float $value, ?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_float($v) && $v >= $value,
            $message ?? Translators::default()->trans('relayer.validation.float.min', ['value' => $value]),
        );
    }

    public function max(float $value, ?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_float($v) && $v <= $value,
            $message ?? Translators::default()->trans('relayer.validation.float.max', ['value' => $value]),
        );
    }

    public function positive(?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_float($v) && $v > 0.0,
            $message ?? Translators::default()->trans('relayer.validation.float.positive'),
        );
    }

    public function nonNegative(?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_float($v) && $v >= 0.0,
            $message ?? Translators::default()->trans('relayer.validation.float.non_negative'),
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

        $errors[$path] = Translators::default()->trans('relayer.validation.float.type');

        return null;
    }
}
