<?php

declare(strict_types=1);

namespace Polidog\Relayer\Validation;

use Polidog\Relayer\I18n\Translators;

final class ArraySchema extends Schema
{
    public function __construct(private readonly Schema $element) {}

    public function min(int $count, ?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_array($v) && \count($v) >= $count,
            $message ?? Translators::default()->transChoice('relayer.validation.array.min', $count, ['min' => $count]),
        );
    }

    public function max(int $count, ?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_array($v) && \count($v) <= $count,
            $message ?? Translators::default()->transChoice('relayer.validation.array.max', $count, ['max' => $count]),
        );
    }

    public function nonEmpty(?string $message = null): static
    {
        return $this->min(1, $message ?? Translators::default()->trans('relayer.validation.array.non_empty'));
    }

    protected function parseDefined(mixed $input, string $path, array &$errors): mixed
    {
        if (!\is_array($input)) {
            $errors[$path] = Translators::default()->trans('relayer.validation.array.type');

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
