<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Document;

use InvalidArgumentException;

/**
 * A single external `<script src>` a page or layout asked the document to
 * emit. Deliberately src-only: inline JS already has {@see HtmlDocument::addHeadHtml()}
 * (the Island loader rides on it), so this stays a thin, escape-safe surface
 * with no second inline-script vector.
 *
 * Built by `PageComponent::addJs()` / `$ctx->js()` / `LayoutComponent::addJs()`
 * and collected into {@see HtmlDocument} by the router after render — the same
 * "page declares, document emits" path metadata already uses.
 */
final class Script
{
    public function __construct(
        public readonly string $src,
        public readonly bool $defer = false,
        public readonly bool $async = false,
        public readonly bool $module = false,
    ) {
        if ('' === \trim($src)) {
            throw new InvalidArgumentException('Script src must be a non-empty path or URL.');
        }
    }

    public function toHtmlTag(): string
    {
        $attributes = [\sprintf('src="%s"', \htmlspecialchars($this->src, \ENT_QUOTES, 'UTF-8'))];

        if ($this->module) {
            $attributes[] = 'type="module"';
        }

        if ($this->defer) {
            $attributes[] = 'defer';
        }

        if ($this->async) {
            $attributes[] = 'async';
        }

        return \sprintf('<script %s></script>', \implode(' ', $attributes));
    }
}
