<?php

declare(strict_types=1);

namespace Polidog\Relayer\Form;

/**
 * Base class for all schema definitions. Inspired by Zod (TypeScript):
 * declare a schema with a fluent builder, then call {@see safeParse} or
 * {@see parse} to coerce / validate an input.
 *
 * Modifiers (`optional`, `nullable`, `default`, `refine`, `transform`)
 * return a new cloned schema; the receiver is never mutated, so a base
 * schema can be reused as a building block.
 */
abstract class Schema
{
    protected bool $optional = false;
    protected bool $nullable = false;
    protected bool $hasDefault = false;
    protected mixed $default = null;

    protected ?string $requiredMessage = null;

    /** @var list<array{0: callable(mixed): bool, 1: string}> */
    protected array $refinements = [];

    /** @var list<callable(mixed): mixed> */
    protected array $transforms = [];

    /**
     * Mark this field as optional: a missing or null input becomes `null`
     * (after default handling) and no further validation runs.
     */
    public function optional(): static
    {
        $clone = clone $this;
        $clone->optional = true;

        return $clone;
    }

    /**
     * Allow `null` as a valid value. Distinct from {@see optional()}: a
     * nullable-but-required field still requires the key to be present.
     */
    public function nullable(): static
    {
        $clone = clone $this;
        $clone->nullable = true;

        return $clone;
    }

    /**
     * Supply a default value used when the input is absent (missing key
     * or, for {@see StringSchema}, an empty string).
     */
    public function default(mixed $value): static
    {
        $clone = clone $this;
        $clone->hasDefault = true;
        $clone->default = $value;

        return $clone;
    }

    /**
     * Override the "Required." message produced when the input is absent
     * and the field is neither optional, nullable, nor defaulted.
     */
    public function required(?string $message = null): static
    {
        $clone = clone $this;
        $clone->optional = false;
        $clone->nullable = false;
        $clone->hasDefault = false;
        $clone->requiredMessage = $message;

        return $clone;
    }

    /**
     * Add an arbitrary validation predicate. Returns the schema unchanged
     * when the predicate returns true; otherwise the supplied message is
     * recorded as this field's error and subsequent refinements/transforms
     * are skipped.
     *
     * @param callable(mixed): bool $predicate
     */
    public function refine(callable $predicate, string $message): static
    {
        $clone = clone $this;
        $clone->refinements[] = [$predicate, $message];

        return $clone;
    }

    /**
     * Run a final transformation on a successfully-validated value. The
     * callable receives the parsed value and returns the value that will
     * be exposed via {@see ParseResult::$data}.
     *
     * @param callable(mixed): mixed $transformer
     */
    public function transform(callable $transformer): static
    {
        $clone = clone $this;
        $clone->transforms[] = $transformer;

        return $clone;
    }

    /**
     * Validate an input and return a {@see ParseResult}. Never throws on
     * validation errors — inspect {@see ParseResult::$success}.
     */
    public function safeParse(mixed $input): ParseResult
    {
        $errors = [];
        $data = $this->parseAtPath($input, '', $errors);

        return [] === $errors ? ParseResult::ok($data) : ParseResult::fail($errors);
    }

    /**
     * Like {@see safeParse} but throws {@see ParseError} on failure.
     */
    public function parse(mixed $input): mixed
    {
        $result = $this->safeParse($input);

        if (!$result->success) {
            throw new ParseError($result->errors);
        }

        return $result->data;
    }

    /**
     * Internal entry point used by composite schemas. Accumulates errors
     * into the shared `$errors` map keyed by dot path so a single parse
     * pass can report every failing field at once.
     *
     * @param array<string, string> $errors
     *
     * @internal
     */
    public function parseAtPath(mixed $input, string $path, array &$errors): mixed
    {
        if ($this->isAbsent($input)) {
            if ($this->hasDefault) {
                return $this->default;
            }

            if ($this->optional) {
                return null;
            }

            if ($this->nullable && null === $input) {
                return null;
            }

            $errors[$path] = $this->requiredMessage ?? 'Required.';

            return null;
        }

        if (null === $input && $this->nullable) {
            return null;
        }

        $value = $this->parseDefined($input, $path, $errors);

        if (isset($errors[$path])) {
            return null;
        }

        foreach ($this->refinements as [$predicate, $message]) {
            if (!$predicate($value)) {
                $errors[$path] = $message;

                return null;
            }
        }

        foreach ($this->transforms as $transformer) {
            $value = $transformer($value);
        }

        return $value;
    }

    /**
     * Decide whether the raw input should be treated as "not provided".
     * Defaults to "only `null` counts as absent"; {@see StringSchema}
     * widens this to include the empty string.
     */
    protected function isAbsent(mixed $input): bool
    {
        return null === $input;
    }

    /**
     * Coerce / validate a guaranteed-present input. Subclasses set an
     * error via `$errors[$path] = '…'` to signal a type-level failure;
     * generic refinements then run from the base class.
     *
     * @param array<string, string> $errors
     */
    abstract protected function parseDefined(mixed $input, string $path, array &$errors): mixed;
}
