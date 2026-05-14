<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router;

use Psr\Container\ContainerInterface;
use Polidog\UsePhp\Component\BaseComponent;
use Polidog\UsePhp\Component\ComponentInterface;
use Polidog\UsePhp\Runtime\Action;
use Polidog\UsePhp\Runtime\ComponentState;
use Polidog\Relayer\Router\Component\ErrorPageComponent;
use Polidog\Relayer\Router\Component\FunctionPage;
use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\Relayer\Router\Document\DocumentInterface;
use Polidog\Relayer\Router\Document\HtmlDocument;
use Polidog\Relayer\Router\Layout\LayoutComponent;
use Polidog\Relayer\Router\Layout\LayoutInterface;
use Polidog\Relayer\Router\Layout\LayoutRenderer;
use Polidog\Relayer\Router\Layout\LayoutStack;
use Polidog\Relayer\Router\Routing\RouteMatch;
use Polidog\Relayer\Router\Routing\Router;
use Polidog\Relayer\Router\Routing\RouterInterface;

class AppRouter
{
    private string $appDirectory;
    private ?ContainerInterface $container;
    private RouterInterface $router;
    private DocumentInterface $document;
    private bool $autoCompilePsx;
    private string $psxCacheDir;

    public function __construct(
        string $appDirectory,
        ?ContainerInterface $container = null,
        bool $autoCompilePsx = false,
        ?string $psxCacheDir = null,
    ) {
        $this->appDirectory = rtrim($appDirectory, '/');
        $this->container = $container;
        $this->router = Router::create($this->appDirectory);
        $this->document = new HtmlDocument();
        $this->autoCompilePsx = $autoCompilePsx;
        // Default cache dir: <projectRoot>/var/cache/psx where projectRoot
        // is the parent of the appDirectory. This matches the usePHP CLI's
        // default of <cwd>/var/cache/psx for the typical layout where the
        // app dir is `src/app` (so cache lands beside src/, not inside it).
        $this->psxCacheDir = $psxCacheDir
            ?? \dirname($this->appDirectory) . '/var/cache/psx';
    }

    public static function create(
        string $appDirectory,
        bool $autoCompilePsx = false,
        ?string $psxCacheDir = null,
    ): self {
        return new self(
            $appDirectory,
            autoCompilePsx: $autoCompilePsx,
            psxCacheDir: $psxCacheDir,
        );
    }

    public function setContainer(ContainerInterface $container): self
    {
        $this->container = $container;
        return $this;
    }

    public function setJsPath(string $path): self
    {
        if ($this->document instanceof HtmlDocument) {
            $this->document->setJsPath($path);
        }
        return $this;
    }

    public function addCssPath(string $path): self
    {
        if ($this->document instanceof HtmlDocument) {
            $this->document->addCssPath($path);
        }
        return $this;
    }

    public function setDocument(DocumentInterface $document): self
    {
        $this->document = $document;
        return $this;
    }

    public function run(): void
    {
        $path = $this->getRequestPath();

        $match = $this->router->match($path);

        if ($match === null) {
            $this->handleNotFound();
            return;
        }

        $this->handleMatch($match);
    }

    private function handleMatch(RouteMatch $match): void
    {
        $layoutStack = $this->loadLayouts($match->getLayoutPaths(), $match->getParams());

        $pageComponent = $this->loadPage($match->getPagePath(), $match->getParams());

        if ($pageComponent === null) {
            $this->handleNotFound();
            return;
        }

        $this->renderPage($pageComponent, $layoutStack, $match->getParams());
    }

    private function handleNotFound(): void
    {
        http_response_code(404);

        $errorPagePath = $this->router->getErrorPagePath();

        if ($errorPagePath !== null) {
            $errorComponent = $this->loadErrorPage($errorPagePath, 404, 'Page not found');

            if ($errorComponent !== null) {
                $rootLayoutPath = $this->findRootLayoutPath();
                $layoutStack = new LayoutStack();

                if ($rootLayoutPath !== null) {
                    $layout = $this->loadLayoutFromFile($rootLayoutPath, []);
                    if ($layout !== null) {
                        $layoutStack->push($layout);
                    }
                }

                $this->renderPage($errorComponent, $layoutStack, []);
                return;
            }
        }

        echo $this->document->renderError(404, 'Page not found');
    }

    /**
     * @param array<string> $layoutPaths
     * @param array<string, string> $params
     */
    private function loadLayouts(array $layoutPaths, array $params): LayoutStack
    {
        $stack = new LayoutStack();

        foreach ($layoutPaths as $layoutPath) {
            $layout = $this->loadLayoutFromFile($layoutPath, $params);
            if ($layout !== null) {
                $stack->push($layout);
            }
        }

        return $stack;
    }

    /**
     * @param array<string, string> $params
     */
    private function loadLayoutFromFile(string $filePath, array $params): ?LayoutInterface
    {
        if (!file_exists($filePath)) {
            return null;
        }

        // .psx is the source; the runtime requires the compiled .psx.php sibling.
        if (str_ends_with($filePath, '.psx')) {
            $filePath = $this->resolveCompiledPsxPath($filePath);
        }

        require_once $filePath;

        $className = $this->getClassFromFile($filePath);

        if ($className === null) {
            return null;
        }

        if (!class_exists($className)) {
            return null;
        }

        $instance = $this->resolveInstance($className);

        if (!$instance instanceof LayoutInterface) {
            return null;
        }

        if ($instance instanceof LayoutComponent) {
            $instance->setParams($params);
        }

        return $instance;
    }

    /**
     * @param array<string, string> $params
     */
    private function loadPage(string $pagePath, array $params): ComponentInterface|FunctionPage|null
    {
        if (!file_exists($pagePath)) {
            return null;
        }

        // .psx is the source; the runtime requires the compiled .psx.php sibling.
        if (str_ends_with($pagePath, '.psx')) {
            $pagePath = $this->resolveCompiledPsxPath($pagePath);
        }

        $result = require_once $pagePath;

        // Closure return: function-based page
        if ($result instanceof \Closure) {
            return $this->buildFunctionPage($result, $pagePath, $params);
        }

        // Class-based page (fallback)
        $className = $this->getClassFromFile($pagePath);

        if ($className !== null && class_exists($className)) {
            $instance = $this->resolveInstance($className);

            if ($instance instanceof ComponentInterface) {
                if ($instance instanceof PageComponent) {
                    $instance->setParams($params);
                }

                return $instance;
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $params
     */
    private function buildFunctionPage(\Closure $factory, string $pagePath, array $params): FunctionPage
    {
        $context = new Component\PageContext($params);
        $renderFn = $factory($context);
        $pageId = $this->computePageId($pagePath);

        return new FunctionPage($renderFn, $context, $pageId);
    }

    private function computePageId(string $pagePath): string
    {
        $relative = str_replace($this->appDirectory, '', $pagePath);
        $relative = preg_replace('#/page\.(psx\.php|psx|php)$#', '', $relative);

        if ($relative === '' || $relative === '/') {
            return '/';
        }

        return $relative;
    }

    /**
     * Resolve a page.psx path to its cached compiled file. The cache file
     * sits in `var/cache/psx/<sha1(realpath(source))>.php` per the usePHP
     * convention (CompileCommand::cachePathFor).
     *
     * Behaviour by mode:
     * - autoCompilePsx=true: when the cache file is missing or older than
     *   the source, the usePHP Compiler runs in-process and rewrites the
     *   cache atomically (temp + rename).
     * - autoCompilePsx=false (default, production): if the cache file is
     *   missing, throw a clear error pointing at `vendor/bin/usephp compile`.
     *   If it exists, it's treated as authoritative — staleness is NOT
     *   re-checked at request time. The deployment / build step owns the
     *   refresh contract via `usephp compile`.
     */
    private function resolveCompiledPsxPath(string $psxPath): string
    {
        $compiledPath = $this->cachePathFor($psxPath);

        if (!$this->autoCompilePsx) {
            if (!file_exists($compiledPath)) {
                throw new \RuntimeException(
                    "Compiled PSX not found for $psxPath (expected $compiledPath). "
                    . 'Run `vendor/bin/usephp compile` to populate the cache directory, '
                    . 'or pass autoCompilePsx: true to AppRouter for dev auto-compile.'
                );
            }
            return $compiledPath;
        }

        if (!class_exists('Polidog\\UsePhp\\Psx\\Compiler')) {
            throw new \RuntimeException(
                'autoCompilePsx is enabled but Polidog\\UsePhp\\Psx\\Compiler '
                . 'is not available. Update polidog/use-php to a version with PSX support.'
            );
        }

        $needsCompile = !file_exists($compiledPath)
            || @filemtime($compiledPath) < @filemtime($psxPath);

        if ($needsCompile) {
            $this->ensureCacheDir();
            $compilerClass = 'Polidog\\UsePhp\\Psx\\Compiler';
            /** @var object{compile: callable} $compiler */
            $compiler = new $compilerClass();
            $source = file_get_contents($psxPath);
            if ($source === false) {
                throw new \RuntimeException("Failed to read PSX source: $psxPath");
            }
            $compiled = $compiler->compile($source);
            $this->atomicWrite($compiledPath, $compiled);
        }

        return $compiledPath;
    }

    /**
     * Write to the destination via a tempfile + rename so concurrent
     * requests never see a partially written compiled file. The tempfile
     * is placed in the same directory as the destination so rename is
     * atomic on POSIX filesystems.
     */
    private function atomicWrite(string $destination, string $content): void
    {
        $dir = \dirname($destination);
        $tmp = @\tempnam($dir, 'psx-');
        if ($tmp === false) {
            throw new \RuntimeException("Failed to create temp file in $dir");
        }
        if (\file_put_contents($tmp, $content) === false) {
            @\unlink($tmp);
            throw new \RuntimeException("Failed to write temp file: $tmp");
        }
        if (!@\rename($tmp, $destination)) {
            @\unlink($tmp);
            throw new \RuntimeException("Failed to rename $tmp to $destination");
        }
    }

    private function cachePathFor(string $sourcePath): string
    {
        // Mirror usePHP's CompileCommand::cachePathFor — same hashing + naming
        // so a pre-compiled cache produced by `vendor/bin/usephp compile` is
        // findable here without consulting the manifest.
        if (\class_exists('Polidog\\UsePhp\\Psx\\CompileCommand')) {
            return \Polidog\UsePhp\Psx\CompileCommand::cachePathFor(
                $this->psxCacheDir,
                $sourcePath,
            );
        }
        // Fallback (CompileCommand not loaded for some reason): use the same
        // algorithm so we never disagree with the upstream tool.
        $abs = \realpath($sourcePath);
        if ($abs === false) {
            $abs = $sourcePath;
        }
        return rtrim($this->psxCacheDir, '/') . '/' . sha1($abs) . '.php';
    }

    private function ensureCacheDir(): void
    {
        if (!is_dir($this->psxCacheDir)) {
            @mkdir($this->psxCacheDir, 0o755, true);
        }
    }

    private function loadErrorPage(string $errorPath, int $statusCode, string $message): ?ComponentInterface
    {
        if (!file_exists($errorPath)) {
            return null;
        }

        // .psx is the source; the runtime requires the compiled .psx.php sibling.
        if (str_ends_with($errorPath, '.psx')) {
            $errorPath = $this->resolveCompiledPsxPath($errorPath);
        }

        require_once $errorPath;

        $className = $this->getClassFromFile($errorPath);

        if ($className === null) {
            return null;
        }

        if (!class_exists($className)) {
            return null;
        }

        $instance = $this->resolveInstance($className);

        if (!$instance instanceof ComponentInterface) {
            return null;
        }

        if ($instance instanceof ErrorPageComponent) {
            $instance->setError($statusCode, $message);
        }

        return $instance;
    }

    /**
     * @param array<string, string> $params
     */
    private function renderPage(ComponentInterface|FunctionPage $page, LayoutStack $layouts, array $params): void
    {
        $componentId = $page instanceof FunctionPage
            ? $page->getComponentId()
            : 'page:' . $page::class;

        $state = ComponentState::getInstance($componentId);
        ComponentState::reset();

        // Handle useState action (onClick etc.) before rendering
        $this->dispatchStateAction($componentId, $state);

        if ($page instanceof BaseComponent) {
            $page->setComponentState($state);
        }

        if ($page instanceof PageComponent) {
            $page->dispatchActionFromRequest();
        }

        $pageElement = $page->render();

        if ($page instanceof FunctionPage && $this->document instanceof HtmlDocument) {
            $this->document->setMetadata($page->getMetadata());
        } elseif ($page instanceof PageComponent && $this->document instanceof HtmlDocument) {
            $this->document->setMetadata($page->getMetadata());
        }

        $renderer = new LayoutRenderer($componentId, $_SERVER['REQUEST_URI'] ?? '/');
        $html = $renderer->render($pageElement, $layouts);

        if (isset($_SERVER['HTTP_X_USEPHP_PARTIAL'])) {
            echo $html;
            return;
        }

        $wrappedHtml = sprintf(
            '<div data-usephp="%s">%s</div>',
            htmlspecialchars($componentId, ENT_QUOTES, 'UTF-8'),
            $html
        );

        $output = $this->document->render($wrappedHtml);

        echo $output;
    }

    /**
     * Handle useState setState actions from POST (onClick, onChange, etc.).
     */
    private function dispatchStateAction(string $componentId, ComponentState $state): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        $actionJson = $_POST['_usephp_action'] ?? null;
        $postComponentId = $_POST['_usephp_component'] ?? null;

        if (!is_string($actionJson) || !is_string($postComponentId)) {
            return;
        }

        // Only handle JSON actions (not usephp-action: form tokens)
        if (str_starts_with($actionJson, 'usephp-action:')) {
            return;
        }

        if ($postComponentId !== $componentId) {
            return;
        }

        try {
            $actionData = json_decode($actionJson, true, 512, JSON_THROW_ON_ERROR);
            $action = Action::fromArray($actionData);

            if ($action->type === 'setState') {
                $index = $action->payload['index'] ?? 0;
                $value = $action->payload['value'] ?? null;
                $state->setState($index, $value);
            }
        } catch (\JsonException) {
            return;
        }

        // PRG pattern: redirect after state change (non-AJAX)
        if (!isset($_SERVER['HTTP_X_USEPHP_PARTIAL'])) {
            $redirectUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
            header('Location: ' . $redirectUrl, true, 303);
            exit;
        }
    }

    /**
     * Resolve a class instance using the container or direct instantiation.
     */
    private function resolveInstance(string $className): object
    {
        if ($this->container !== null && $this->container->has($className)) {
            return $this->container->get($className);
        }

        return new $className();
    }

    private function getClassFromFile(string $filePath): ?string
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            return null;
        }

        $tokens = token_get_all($content);
        $tokenCount = count($tokens);
        $namespace = null;
        $className = null;

        for ($i = 0; $i < $tokenCount; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespaceParts = [];
                $i++;

                while ($i < $tokenCount) {
                    $nextToken = $tokens[$i];

                    if ($nextToken === ';' || $nextToken === '{') {
                        break;
                    }

                    if (is_array($nextToken)) {
                        if ($nextToken[0] === T_NAME_QUALIFIED || $nextToken[0] === T_STRING) {
                            $namespaceParts[] = $nextToken[1];
                        }
                    }

                    $i++;
                }

                $namespace = implode('', $namespaceParts);
            }

            if ($token[0] === T_CLASS) {
                $i++;

                while ($i < $tokenCount) {
                    $nextToken = $tokens[$i];

                    if (is_array($nextToken) && $nextToken[0] === T_STRING) {
                        $className = $nextToken[1];
                        break;
                    }

                    if (is_array($nextToken) && $nextToken[0] === T_WHITESPACE) {
                        $i++;
                        continue;
                    }

                    break;
                }

                if ($className !== null) {
                    break;
                }
            }
        }

        if ($className === null) {
            return null;
        }

        if ($namespace !== null) {
            return $namespace . '\\' . $className;
        }

        return $className;
    }

    private function findRootLayoutPath(): ?string
    {
        foreach (['layout.psx', 'layout.php'] as $name) {
            $candidate = $this->appDirectory . '/' . $name;
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function getRequestPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH);

        return $path ?? '/';
    }
}
