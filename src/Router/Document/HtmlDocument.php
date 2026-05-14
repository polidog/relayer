<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Document;

final class HtmlDocument implements DocumentInterface
{
    private string $lang = 'en';
    private string $title = 'usePHP App';
    private string $jsPath = '/usephp.js';
    /** @var array<int, string> */
    private array $cssPaths = [];
    private bool $includeDefaultStyles = true;
    /** @var array<int, string> */
    private array $headHtml = [];
    /** @var array<string, string> */
    private array $metadata = [];

    public static function create(): self
    {
        return new self();
    }

    public function setLang(string $lang): self
    {
        $this->lang = $lang;
        return $this;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;
        return $this;
    }

    public function addHeadHtml(string $html): self
    {
        $this->headHtml[] = $html;
        return $this;
    }

    /**
     * @param array<string, string> $metadata
     */
    public function setMetadata(array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function setJsPath(string $path): self
    {
        $this->jsPath = $path;
        return $this;
    }

    public function addCssPath(string $path): self
    {
        $this->cssPaths[] = $path;
        return $this;
    }

    public function disableDefaultStyles(): self
    {
        $this->includeDefaultStyles = false;
        return $this;
    }

    public function render(string $content): string
    {
        $cssLinks = $this->getCssLinks();
        $defaultStyles = $this->getDefaultStyles();
        $headHtml = $this->getHeadHtml();
        $title = $this->getTitle();
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $escapedLang = htmlspecialchars($this->lang, ENT_QUOTES, 'UTF-8');
        $escapedJsPath = htmlspecialchars($this->jsPath, ENT_QUOTES, 'UTF-8');
        $metaTags = $this->getMetaTags();

        return <<<HTML
<!DOCTYPE html>
<html lang="{$escapedLang}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$escapedTitle}</title>
    {$cssLinks}
    {$metaTags}
    {$headHtml}
    {$defaultStyles}
</head>
<body>
    {$content}
    <script src="{$escapedJsPath}"></script>
</body>
</html>
HTML;
    }

    public function renderError(int $statusCode, string $message): string
    {
        $escapedMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $escapedTitle = htmlspecialchars("{$statusCode} - {$message}", ENT_QUOTES, 'UTF-8');
        $escapedLang = htmlspecialchars($this->lang, ENT_QUOTES, 'UTF-8');
        $cssLinks = $this->getCssLinks();
        $headHtml = $this->getHeadHtml();
        $defaultStyles = $this->getDefaultErrorStyles();
        $metaTags = $this->getMetaTags();

        return <<<HTML
<!DOCTYPE html>
<html lang="{$escapedLang}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$escapedTitle}</title>
    {$cssLinks}
    {$metaTags}
    {$headHtml}
    {$defaultStyles}
</head>
<body>
    <div class="error">
        <h1>{$statusCode}</h1>
        <p>{$escapedMessage}</p>
    </div>
</body>
</html>
HTML;
    }

    private function getCssLinks(): string
    {
        if ($this->cssPaths === []) {
            return '';
        }

        $links = [];

        foreach ($this->cssPaths as $path) {
            $escapedPath = htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
            $links[] = sprintf('<link rel="stylesheet" href="%s">', $escapedPath);
        }

        return implode("\n    ", $links);
    }

    private function getHeadHtml(): string
    {
        if ($this->headHtml === []) {
            return '';
        }

        return implode("\n    ", $this->headHtml);
    }

    private function getTitle(): string
    {
        $title = $this->metadata['title'] ?? null;

        if (is_string($title) && $title !== '') {
            return $title;
        }

        return $this->title;
    }

    private function getMetaTags(): string
    {
        if ($this->metadata === []) {
            return '';
        }

        $tags = [];

        foreach ($this->metadata as $key => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            if ($key === 'title') {
                continue;
            }

            $escapedValue = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

            if ($key === 'description') {
                $tags[] = sprintf('<meta name="description" content="%s">', $escapedValue);
                continue;
            }

            if (str_starts_with($key, 'og:')) {
                $tags[] = sprintf('<meta property="%s" content="%s">', htmlspecialchars($key, ENT_QUOTES, 'UTF-8'), $escapedValue);
                continue;
            }

            $tags[] = sprintf('<meta name="%s" content="%s">', htmlspecialchars($key, ENT_QUOTES, 'UTF-8'), $escapedValue);
        }

        if ($tags === []) {
            return '';
        }

        return implode("\n    ", $tags);
    }

    private function getDefaultStyles(): string
    {
        if (!$this->includeDefaultStyles) {
            return '';
        }

        return <<<HTML
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 0;
            background: #f5f5f5;
        }
        [aria-busy="true"] {
            opacity: 0.6;
            pointer-events: none;
        }
    </style>
HTML;
    }

    private function getDefaultErrorStyles(): string
    {
        if (!$this->includeDefaultStyles) {
            return '';
        }

        return <<<HTML
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background: #f5f5f5;
        }
        .error {
            text-align: center;
        }
        h1 {
            font-size: 72px;
            margin: 0;
            color: #333;
        }
        p {
            font-size: 24px;
            color: #666;
        }
    </style>
HTML;
    }
}
