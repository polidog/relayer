<?php

declare(strict_types=1);

use Polidog\Rosanna\Html\H;
use Polidog\Rosanna\Runtime\Element;

return function (array $children): Element {
    return H::div(children: $children);
};
