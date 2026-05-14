<?php

declare(strict_types=1);

use Polidog\UsePhp\Html\H;
use Polidog\UsePhp\Runtime\Element;

return function (array $children): Element {
    return H::div(children: $children);
};
