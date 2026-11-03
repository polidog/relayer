<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\I18n\LocaleResolver;
use Polidog\Relayer\I18n\Translator;
use Polidog\Relayer\InjectorContainer;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Component\PageComponent;
use Polidog\Relayer\Tests\Fixtures\PlainService;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * A page, layout or error page registered as a (shared) container service is
 * one object for the container's whole life, which on a worker is many
 * requests. What a render adds to it (scripts, metadata, params, children, a
 * form action's state) must not reach the next request, and the service the
 * container holds must never be the object a request writes to.
 */
final class SharedPageServiceDispatchTest extends TestCase
{
    private string $workDir;

    private string $namespace;

    private ContainerBuilder $builder;

    protected function setUp(): void
    {
        $token = \bin2hex(\random_bytes(6));
        $this->workDir = \sys_get_temp_dir() . '/shared-page-' . $token;
        \mkdir($this->workDir . '/items/[id]', 0o777, true);

        // Unique namespace per test: the class-style loader uses
        // require_once, and each test writes its own copy of the files.
        $this->namespace = 'Polidog\Relayer\Tests\Tmp\SharedPage\T' . $token;

        \file_put_contents($this->workDir . '/layout.php', \sprintf(<<<'PHP'
            <?php
            declare(strict_types=1);
            namespace %s;
            use Polidog\Relayer\Router\Layout\LayoutComponent;
            use Polidog\Rosanna\Runtime\Element;
            final class RootLayout extends LayoutComponent
            {
                public int $renders = 0;

                public function render(): Element
                {
                    ++$this->renders;
                    $this->addJs('/layout.js');

                    return new Element('main', [], ['layout-renders=' . $this->renders . ';', $this->getChildren()]);
                }
            }
            PHP, $this->namespace));

        \file_put_contents($this->workDir . '/items/[id]/page.php', \sprintf(<<<'PHP'
            <?php
            declare(strict_types=1);
            namespace %s;
            use Polidog\Relayer\Router\Component\PageComponent;
            use Polidog\Relayer\Tests\Fixtures\PlainService;
            use Polidog\Rosanna\Runtime\Element;
            final class ItemPage extends PageComponent
            {
                public int $renders = 0;

                public function __construct(public readonly PlainService $service) {}

                public function render(): Element
                {
                    ++$this->renders;
                    $this->addJs('/page.js');
                    if (null !== $this->getQuery('secret')) {
                        $this->setMetadata(['description' => 'secret-meta']);
                    }

                    return new Element('p', [], [
                        'page-renders=' . $this->renders
                        . ';id=' . ($this->getParam('id') ?? '-')
                        . ';service=' . \spl_object_id($this->service),
                    ]);
                }
            }
            PHP, $this->namespace));

        \file_put_contents($this->workDir . '/error.php', \sprintf(<<<'PHP'
            <?php
            declare(strict_types=1);
            namespace %s;
            use Polidog\Relayer\Router\Component\ErrorPageComponent;
            use Polidog\Rosanna\Runtime\Element;
            final class ErrorPage extends ErrorPageComponent
            {
                public int $renders = 0;

                public function render(): Element
                {
                    ++$this->renders;

                    return new Element('p', [], ['error-renders=' . $this->renders . ';status=' . $this->getStatusCode()]);
                }
            }
            PHP, $this->namespace));

        $this->builder = new ContainerBuilder();
        // InjectorContainer autowires any loadable class, these included,
        // so a bare builder has to bind them.
        $this->builder->set(LocaleResolver::class, new LocaleResolver(['en'], 'en'));
        $this->builder->set(Translator::class, Translator::framework());
        $this->builder->register(PlainService::class, PlainService::class)->setPublic(true);
        foreach (['RootLayout', 'ItemPage', 'ErrorPage'] as $class) {
            $this->builder->register($this->namespace . '\\' . $class, $this->namespace . '\\' . $class)->setPublic(true);
        }
        $this->builder->getDefinition($this->namespace . '\ItemPage')->addArgument(new Reference(PlainService::class));

        \http_response_code(200);
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        @\unlink($this->workDir . '/items/[id]/page.php');
        @\rmdir($this->workDir . '/items/[id]');
        @\rmdir($this->workDir . '/items');
        @\unlink($this->workDir . '/layout.php');
        @\unlink($this->workDir . '/error.php');
        @\rmdir($this->workDir);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
        $_GET = [];
        $_POST = [];
    }

    public function testScriptsAreNotAccumulatedAcrossRequests(): void
    {
        $router = $this->router();

        $this->dispatch($router, '/items/1');
        $second = $this->dispatch($router, '/items/1');

        self::assertSame(1, \substr_count($second, '/page.js'), $second);
        self::assertSame(1, \substr_count($second, '/layout.js'), $second);
        self::assertStringContainsString('page-renders=1;', $second);
        self::assertStringContainsString('layout-renders=1;', $second);
    }

    public function testMetadataAndParamsDoNotReachTheNextVisitor(): void
    {
        $router = $this->router();

        self::assertStringContainsString('secret-meta', $this->dispatch($router, '/items/1?secret=1'));

        $next = $this->dispatch($router, '/items/2');

        self::assertStringNotContainsString('secret-meta', $next);
        self::assertStringContainsString(';id=2;', $next);
    }

    public function testInjectedDependenciesAreKept(): void
    {
        $output = $this->dispatch($this->router(), '/items/1');

        $service = $this->builder->get(PlainService::class);
        self::assertStringContainsString(';service=' . \spl_object_id($service), $output);
    }

    public function testTheContainerServicesAreNeverWrittenTo(): void
    {
        $router = $this->router();

        $this->dispatch($router, '/items/1?secret=1');
        $this->dispatch($router, '/missing');

        foreach (['RootLayout', 'ItemPage', 'ErrorPage'] as $class) {
            $service = $this->builder->get($this->namespace . '\\' . $class);
            self::assertTrue(\property_exists($service, 'renders'));
            self::assertSame(0, $service->renders, $class . ' rendered on the shared service');
        }

        $page = $this->builder->get($this->namespace . '\ItemPage');
        self::assertInstanceOf(PageComponent::class, $page);
        self::assertSame([], $page->getScripts());
        self::assertSame([], $page->getMetadata());
    }

    public function testErrorPageIsFreshForEveryRequest(): void
    {
        $router = $this->router();

        $this->dispatch($router, '/missing');
        $second = $this->dispatch($router, '/missing');

        self::assertSame(404, \http_response_code());
        self::assertStringContainsString('error-renders=1;status=404', $second);
    }

    private function router(): AppRouter
    {
        return AppRouter::create($this->workDir)->setContainer(new InjectorContainer($this->builder));
    }

    private function dispatch(AppRouter $router, string $uri): string
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $uri;
        $query = \parse_url($uri, \PHP_URL_QUERY);
        $_GET = [];
        if (\is_string($query)) {
            \parse_str($query, $_GET);
        }

        \ob_start();

        try {
            $router->run();
        } finally {
            $output = (string) \ob_get_clean();
        }

        return $output;
    }
}
