<?php

declare(strict_types=1);

use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\Document\HtmlDocument;

require_once __DIR__ . '/../vendor/autoload.php';

$document = HtmlDocument::create()
    ->disableDefaultStyles()
    ->addHeadHtml('<script src="https://cdn.tailwindcss.com"></script>')
    ->addHeadHtml('<style>body{font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}</style>');

Relayer::boot(__DIR__ . '/..')
    ->setDocument($document)
    ->run();
