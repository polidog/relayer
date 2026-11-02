<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Fixtures;

use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\Rosanna\Runtime\Element;

/**
 * Class-style page with one exposed form action (`save`) plus methods that
 * are never exposed through action(), used to prove the dispatcher only runs
 * what the server signed.
 */
final class FormActionPage extends PageComponent
{
    /** @var list<array{method: string, formData: array<array-key, mixed>, id: null|int|string}> */
    public array $calls = [];

    public function render(): Element
    {
        return new Element('div', [], []);
    }

    /**
     * @param array<string, mixed> $args
     */
    public function saveToken(array $args = []): string
    {
        return $this->action([$this, 'save'], $args);
    }

    /**
     * Tries to expose a method inherited from PageComponent — must throw.
     */
    public function tokenForInherited(): string
    {
        return $this->action([$this, 'getMetadata']);
    }

    /**
     * @param array<array-key, mixed> $formData
     */
    public function save(array $formData, int|string|null $id = null): void
    {
        $this->calls[] = ['method' => 'save', 'formData' => $formData, 'id' => $id];
    }

    /**
     * Public, but never handed to action().
     *
     * @param array<array-key, mixed> $formData
     */
    public function unregisteredPublic(array $formData): void
    {
        $this->calls[] = ['method' => 'unregisteredPublic', 'formData' => $formData, 'id' => null];
    }

    /**
     * @param array<array-key, mixed> $formData
     */
    protected function internalOnly(array $formData): void
    {
        $this->calls[] = ['method' => 'internalOnly', 'formData' => $formData, 'id' => null];
    }
}
