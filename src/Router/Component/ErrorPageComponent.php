<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Component;

use Polidog\Rosanna\Runtime\Renderable;

abstract class ErrorPageComponent implements Renderable
{
    private int $statusCode = 404;
    private string $message = 'Not Found';

    /**
     * @internal
     */
    public function setError(int $statusCode, string $message): void
    {
        $this->statusCode = $statusCode;
        $this->message = $message;
    }

    protected function getStatusCode(): int
    {
        return $this->statusCode;
    }

    protected function getMessage(): string
    {
        return $this->message;
    }
}
