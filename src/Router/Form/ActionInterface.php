<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Form;

interface ActionInterface
{
    /**
     * @param array<string, mixed> $form
     */
    public function handle(array $form): void;
}
