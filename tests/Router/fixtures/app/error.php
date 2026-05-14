<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Fixtures;

use Polidog\UsePhp\Runtime\Element;
use Polidog\Relayer\Router\Component\ErrorPageComponent;

class ErrorPage extends ErrorPageComponent
{
    public function render(): Element
    {
        return new Element('div', ['className' => 'error'], [
            new Element('h1', [], [(string) $this->getStatusCode()]),
            new Element('p', [], [$this->getMessage()]),
        ]);
    }
}
