<?php

declare(strict_types=1);

namespace Polidog\Relayer\Form;

final class ObjectSchema extends Schema
{
    /**
     * @param array<string, Schema> $shape
     * @param bool                  $stripUnknown when true (the default),
     *                                            input keys that are not in
     *                                            `$shape` are silently dropped
     *                                            from the parsed result
     */
    public function __construct(
        private readonly array $shape,
        private readonly bool $stripUnknown = true,
    ) {}

    /**
     * Keep input keys not declared in the schema. Useful when forwarding
     * extra metadata fields to a handler without listing every key.
     */
    public function passthrough(): self
    {
        return new self($this->shape, false);
    }

    protected function parseDefined(mixed $input, string $path, array &$errors): mixed
    {
        if (!\is_array($input)) {
            $errors[$path] = 'Must be an object.';

            return null;
        }

        /** @var array<string, mixed> $result */
        $result = [];

        foreach ($this->shape as $key => $fieldSchema) {
            $childPath = '' === $path ? $key : $path . '.' . $key;
            $rawValue = $input[$key] ?? null;
            $parsed = $fieldSchema->parseAtPath($rawValue, $childPath, $errors);
            $result[$key] = $parsed;
        }

        if (!$this->stripUnknown) {
            foreach ($input as $key => $value) {
                if (!\is_string($key)) {
                    continue;
                }
                if (!\array_key_exists($key, $this->shape)) {
                    $result[$key] = $value;
                }
            }
        }

        return $result;
    }
}
