<?php

declare(strict_types=1);

namespace Polidog\Relayer\Validation;

use Polidog\Relayer\I18n\Translators;

final class IntSchema extends Schema
{
    public function min(int $value, ?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_int($v) && $v >= $value,
            $message ?? Translators::default()->trans('relayer.validation.int.min', ['value' => $value]),
        );
    }

    public function max(int $value, ?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_int($v) && $v <= $value,
            $message ?? Translators::default()->trans('relayer.validation.int.max', ['value' => $value]),
        );
    }

    public function positive(?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_int($v) && $v > 0,
            $message ?? Translators::default()->trans('relayer.validation.int.positive'),
        );
    }

    public function nonNegative(?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_int($v) && $v >= 0,
            $message ?? Translators::default()->trans('relayer.validation.int.non_negative'),
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
        if (\is_int($input)) {
            return $input;
        }

        if (\is_string($input)) {
            $filtered = \filter_var(\trim($input), \FILTER_VALIDATE_INT);
            if (false !== $filtered) {
                return $filtered;
            }
        }

        $errors[$path] = Translators::default()->trans('relayer.validation.int.type');

        return null;
    }
}
