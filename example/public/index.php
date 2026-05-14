<?php

declare(strict_types=1);

use Polidog\Relayer\Relayer;

require_once __DIR__ . '/../vendor/autoload.php';

Relayer::boot(__DIR__ . '/..')->run();
