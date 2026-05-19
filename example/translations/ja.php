<?php

declare(strict_types=1);

/*
 * Example app catalog. Project catalogs are merged over (and can override)
 * the framework's own `relayer.*` keys. Inject Polidog\Relayer\I18n\Translator
 * into a page/layout/service and call ->trans('app.welcome').
 */

return [
    'app.welcome' => 'ようこそ',
    'app.items' => '{count}件|{count}件',
];
