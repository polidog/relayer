<?php

declare(strict_types=1);

namespace Polidog\Relayer\Validation;

use RuntimeException;

final class ParseError extends RuntimeException
{
    /**
     * @param array<string, string> $errors flat field -> message map (nested fields use dot-paths)
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct($this->summarize($errors));
    }

    /**
     * @param array<string, string> $errors
     */
    private function summarize(array $errors): string
    {
        if ([] === $errors) {
            return 'Schema parse failed.';
        }

        $parts = [];
        foreach ($errors as $path => $message) {
            $parts[] = '' === $path ? $message : "{$path}: {$message}";
        }

        return 'Schema parse failed: ' . \implode('; ', $parts);
    }
}
