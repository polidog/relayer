<?php

declare(strict_types=1);

namespace Polidog\Relayer\Form;

final class EnumSchema extends Schema
{
    /** @var list<string> */
    private readonly array $values;

    /**
     * @param list<string> $values
     */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    protected function isAbsent(mixed $input): bool
    {
        if (null === $input) {
            return true;
        }

        if (\is_string($input) && '' === $input) {
            return true;
        }

        return false;
    }

    protected function parseDefined(mixed $input, string $path, array &$errors): mixed
    {
        if (!\is_string($input) || !\in_array($input, $this->values, true)) {
            $errors[$path] = \sprintf(
                'Must be one of: %s.',
                \implode(', ', $this->values),
            );

            return null;
        }

        return $input;
    }
}
