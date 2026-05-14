<?php

declare(strict_types=1);

namespace Polidog\Relayer\Form;

final class BoolSchema extends Schema
{
    private const TRUTHY = ['1', 'true', 'on', 'yes'];
    private const FALSY = ['0', 'false', 'off', 'no'];

    public function true(?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => true === $v,
            $message ?? 'Must be true.',
        );
    }

    /**
     * For HTML checkboxes the unchecked state simply omits the field, so
     * the schema treats a missing value as `false` rather than "Required.".
     * Use {@see required()} explicitly if a tristate (must-be-set) bool
     * is what you want.
     */
    protected function isAbsent(mixed $input): bool
    {
        return false;
    }

    protected function parseDefined(mixed $input, string $path, array &$errors): mixed
    {
        if (\is_bool($input)) {
            return $input;
        }

        if (null === $input) {
            return false;
        }

        if (\is_int($input)) {
            return 0 !== $input;
        }

        if (\is_string($input)) {
            $normalized = \strtolower(\trim($input));
            if ('' === $normalized) {
                return false;
            }
            if (\in_array($normalized, self::TRUTHY, true)) {
                return true;
            }
            if (\in_array($normalized, self::FALSY, true)) {
                return false;
            }
        }

        $errors[$path] = 'Must be a boolean.';

        return null;
    }
}
