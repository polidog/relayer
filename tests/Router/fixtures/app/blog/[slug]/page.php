<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Fixtures\Blog;

use Polidog\UsePhp\Runtime\Element;
use Polidog\Relayer\Router\Component\PageComponent;

class BlogDetailPage extends PageComponent
{
    public function render(): Element
    {
        $slug = $this->getParam('slug') ?? 'unknown';
        return new Element('div', [], ['Blog: ' . $slug]);
    }
}
