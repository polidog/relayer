<?php

declare(strict_types=1);

namespace Polidog\Relayer\Validation;

final class StringSchema extends Schema
{
    private bool $trim = false;
    private ?int $case = null; // 0 = lower, 1 = upper

    public function min(int $length, ?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_string($v) && \mb_strlen($v) >= $length,
            $message ?? \sprintf('Must be at least %d character%s.', $length, 1 === $length ? '' : 's'),
        );
    }

    public function max(int $length, ?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_string($v) && \mb_strlen($v) <= $length,
            $message ?? \sprintf('Must be at most %d character%s.', $length, 1 === $length ? '' : 's'),
        );
    }

    public function length(int $length, ?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_string($v) && \mb_strlen($v) === $length,
            $message ?? \sprintf('Must be exactly %d character%s.', $length, 1 === $length ? '' : 's'),
        );
    }

    public function regex(string $pattern, ?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_string($v) && 1 === \preg_match($pattern, $v),
            $message ?? 'Invalid format.',
        );
    }

    public function email(?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_string($v) && false !== \filter_var($v, \FILTER_VALIDATE_EMAIL),
            $message ?? 'Please enter a valid email address.',
        );
    }

    public function url(?string $message = null): static
    {
        return $this->refine(
            static fn (mixed $v): bool => \is_string($v) && false !== \filter_var($v, \FILTER_VALIDATE_URL),
            $message ?? 'Please enter a valid URL.',
        );
    }

    /**
     * Strip leading/trailing whitespace before validation runs. Applied
     * eagerly during {@see parseDefined()} so length / regex / email
     * checks see the trimmed value.
     */
    public function trim(): static
    {
        $clone = clone $this;
        $clone->trim = true;

        return $clone;
    }

    public function lower(): static
    {
        $clone = clone $this;
        $clone->case = 0;

        return $clone;
    }

    public function upper(): static
    {
        $clone = clone $this;
        $clone->case = 1;

        return $clone;
    }

    /**
     * Form inputs always arrive as strings, but an empty string is
     * semantically "not filled in" — so treat it as absent for the
     * optional / required / default machinery to work intuitively.
     */
    protected function isAbsent(mixed $input): bool
    {
        if (null === $input) {
            return true;
        }

        if (\is_string($input) && '' === ($this->trim ? \trim($input) : $input)) {
            return true;
        }

        return false;
    }

    protected function parseDefined(mixed $input, string $path, array &$errors): mixed
    {
        if (!\is_string($input)) {
            // Form input is always a string; reject array / int / etc.
            $errors[$path] = 'Must be a string.';

            return null;
        }

        if ($this->trim) {
            $input = \trim($input);
        }

        if (0 === $this->case) {
            $input = \mb_strtolower($input);
        } elseif (1 === $this->case) {
            $input = \mb_strtoupper($input);
        }

        return $input;
    }
}
