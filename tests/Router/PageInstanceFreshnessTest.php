<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\AppConfigurator;
use Polidog\Relayer\Di\ContainerFactory;
use Polidog\Relayer\I18n\LocaleResolver;
use Polidog\Relayer\I18n\Translator;
use Polidog\Relayer\InjectorContainer;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Tests\Fixtures\RecordingLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;

/**
 * Every class the router uses as a page, layout or error page is a new
 * object per request — also one that only implements `Renderable` — and an
 * object that is already new is used as it is, so a page that cannot be
 * cloned still renders. Only an object the container may hand to every
 * request (one put in with `set()`) is cloned, and when it cannot be, the
 * request fails with an error that says what to change.
 */
final class PageInstanceFreshnessTest extends TestCase
{
    private string $workDir;

    private string $pagesDir;

    private string $namespace;

    protected function setUp(): void
    {
        $token = \bin2hex(\random_bytes(6));
        $this->workDir = \sys_get_temp_dir() . '/page-instance-' . $token;
        $this->pagesDir = $this->workDir . '/src/Pages';
        \mkdir($this->pagesDir . '/form', 0o777, true);
        \mkdir($this->pagesDir . '/noclone', 0o777, true);
        \mkdir($this->workDir . '/src/Components', 0o777, true);

        $this->namespace = 'Polidog\Relayer\Tests\Tmp\PageInstance\T' . $token;

        \file_put_contents($this->pagesDir . '/layout.php', \sprintf(<<<'PHP'
            <?php
            declare(strict_types=1);
            namespace %s;
            use Polidog\Relayer\Router\Layout\LayoutComponent;
            use Polidog\Rosanna\Runtime\Element;
            final class RootLayout extends LayoutComponent
            {
                public function render(): Element
                {
                    return new Element('main', [], [$this->getChildren()]);
                }
            }
            PHP, $this->namespace));

        \file_put_contents($this->pagesDir . '/noclone/layout.php', \sprintf(<<<'PHP'
            <?php
            declare(strict_types=1);
            namespace %s;
            use Polidog\Relayer\Router\Layout\LayoutComponent;
            use Polidog\Rosanna\Runtime\Element;
            final class NoCloneLayout extends LayoutComponent
            {
                public function render(): Element
                {
                    return new Element('section', [], ['no-clone-layout;', $this->getChildren()]);
                }

                private function __clone() {}
            }
            PHP, $this->namespace));

        \file_put_contents($this->pagesDir . '/form/page.php', \sprintf(<<<'PHP'
            <?php
            declare(strict_types=1);
            namespace %s;
            use ArrayObject;
            use Polidog\Rosanna\Runtime\Element;
            use Polidog\Rosanna\Runtime\Renderable;
            final class RenderableFormPage implements Renderable
            {
                /** @var ArrayObject<string, string> */
                public readonly ArrayObject $form;

                public function __construct()
                {
                    $this->form = new ArrayObject();
                }

                public function render(): Element
                {
                    $name = $_GET['name'] ?? null;
                    if (\is_string($name)) {
                        $this->form['name'] = $name;
                    }

                    return new Element('p', [], ['form-name=' . ($this->form['name'] ?? '-') . ';']);
                }
            }
            PHP, $this->namespace));

        \file_put_contents($this->pagesDir . '/noclone/page.php', \sprintf(<<<'PHP'
            <?php
            declare(strict_types=1);
            namespace %s;
            use Polidog\Relayer\Router\Component\PageComponent;
            use Polidog\Rosanna\Runtime\Element;
            final class NoClonePage extends PageComponent
            {
                public function render(): Element
                {
                    return new Element('p', [], ['no-clone-page;']);
                }

                private function __clone() {}
            }
            PHP, $this->namespace));

        // A Renderable that is not a route file: a plain component.
        \file_put_contents($this->workDir . '/src/Components/Widget.php', \sprintf(<<<'PHP'
            <?php
            declare(strict_types=1);
            namespace %s;
            use Polidog\Rosanna\Runtime\Element;
            use Polidog\Rosanna\Runtime\Renderable;
            final class Widget implements Renderable
            {
                public function render(): Element
                {
                    return new Element('span', [], ['widget']);
                }
            }
            PHP, $this->namespace));

        require_once $this->pagesDir . '/layout.php';

        require_once $this->pagesDir . '/noclone/layout.php';

        require_once $this->pagesDir . '/form/page.php';

        require_once $this->pagesDir . '/noclone/page.php';

        require_once $this->workDir . '/src/Components/Widget.php';

        \http_response_code(200);
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->workDir . '/*.php') ?: [] as $file) {
            @\unlink($file);
        }
        @\unlink($this->pagesDir . '/layout.php');
        @\unlink($this->pagesDir . '/form/page.php');
        @\unlink($this->pagesDir . '/noclone/page.php');
        @\unlink($this->pagesDir . '/noclone/layout.php');
        @\unlink($this->workDir . '/src/Components/Widget.php');
        @\rmdir($this->pagesDir . '/form');
        @\rmdir($this->pagesDir . '/noclone');
        @\rmdir($this->pagesDir);
        @\rmdir($this->workDir . '/src/Components');
        @\rmdir($this->workDir . '/src');
        @\rmdir($this->workDir);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
        $_GET = [];
        $_POST = [];
    }

    #[DataProvider('provideConstructorStateOfARenderableOnlyPageDoesNotReachTheNextVisitorCases')]
    public function testConstructorStateOfARenderableOnlyPageDoesNotReachTheNextVisitor(bool $dumped, bool $explicitlyShared): void
    {
        $router = $this->router($this->container($dumped, register: true, explicitlyShared: $explicitlyShared));

        self::assertStringContainsString('form-name=alice;', $this->dispatch($router, '/form?name=alice'));

        $next = $this->dispatch($router, '/form');

        self::assertStringContainsString('form-name=-;', $next);
        self::assertStringNotContainsString('alice', $next);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function provideConstructorStateOfARenderableOnlyPageDoesNotReachTheNextVisitorCases(): iterable
    {
        yield 'live container' => [false, false];

        yield 'dumped container' => [true, false];

        yield 'live container, registered as shared on purpose' => [false, true];

        yield 'dumped container, registered as shared on purpose' => [true, true];
    }

    public function testOnlyRouteFilesBecomeNonSharedServices(): void
    {
        $container = $this->build(forDump: false, register: true, explicitlyShared: false);

        foreach (['RenderableFormPage', 'NoClonePage', 'NoCloneLayout'] as $class) {
            self::assertFalse($container->getDefinition($this->namespace . '\\' . $class)->isShared(), $class);
        }
        self::assertTrue($container->getDefinition($this->namespace . '\Widget')->isShared());
    }

    #[DataProvider('provideAPageAndLayoutThatCannotBeClonedRenderCases')]
    public function testAPageAndLayoutThatCannotBeClonedRender(bool $dumped, bool $register): void
    {
        $router = $this->router($this->container($dumped, register: $register, explicitlyShared: false));

        foreach ([1, 2] as $_) {
            $output = $this->dispatch($router, '/noclone');

            self::assertSame(200, \http_response_code(), $output);
            self::assertStringContainsString('no-clone-layout;', $output);
            self::assertStringContainsString('no-clone-page;', $output);
        }
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function provideAPageAndLayoutThatCannotBeClonedRenderCases(): iterable
    {
        yield 'autowired' => [false, false];

        yield 'registered, live container' => [false, true];

        yield 'registered, dumped container' => [true, true];
    }

    public function testASharedInstanceThatCannotBeClonedIsAConfigurationError(): void
    {
        $builder = new ContainerBuilder();
        $builder->set(LocaleResolver::class, new LocaleResolver(['en'], 'en'));
        $builder->set(Translator::class, Translator::framework());
        $logger = new RecordingLogger();
        $builder->set(LoggerInterface::class, $logger);
        $class = $this->namespace . '\NoClonePage';
        $builder->set($class, new $class());

        $this->dispatch($this->router($builder), '/noclone');

        self::assertSame(500, \http_response_code());
        $messages = \implode("\n", \array_column($logger->records, 'message'));
        self::assertStringContainsString('RuntimeException', $messages);
        self::assertStringContainsString($class, $messages);
        self::assertStringContainsString('non-shared', $messages);
    }

    private function container(bool $dumped, bool $register, bool $explicitlyShared): ContainerInterface
    {
        $builder = $this->build($dumped, $register, $explicitlyShared);

        if (!$dumped) {
            return $builder;
        }

        $className = 'PageInstanceDump_' . \bin2hex(\random_bytes(4));
        $namespace = 'Polidog\Relayer\Tests\Router\Dump';
        $code = (new PhpDumper($builder))->dump(['class' => $className, 'namespace' => $namespace]);
        self::assertIsString($code);

        $file = $this->workDir . '/' . $className . '.php';
        \file_put_contents($file, $code);

        require $file;

        $fqcn = $namespace . '\\' . $className;
        $loaded = new $fqcn();
        self::assertInstanceOf(ContainerInterface::class, $loaded);

        return $loaded;
    }

    private function build(bool $forDump, bool $register, bool $explicitlyShared): ContainerBuilder
    {
        $classes = $register ? ['RenderableFormPage', 'NoClonePage', 'NoCloneLayout', 'Widget'] : [];
        $configurator = new class($this->workDir, $this->namespace, $classes, $explicitlyShared) extends AppConfigurator {
            /**
             * @param list<string> $classes
             */
            public function __construct(
                string $projectRoot,
                private readonly string $namespace,
                private readonly array $classes,
                private readonly bool $explicitlyShared,
            ) {
                parent::__construct($projectRoot);
            }

            public function configure(ContainerBuilder $container): void
            {
                foreach ($this->classes as $class) {
                    $definition = $container->register($this->namespace . '\\' . $class, $this->namespace . '\\' . $class);
                    if ($this->explicitlyShared) {
                        $definition->setShared(true);
                    }
                }
            }
        };

        return ContainerFactory::create($this->workDir, $configurator, false, forDump: $forDump);
    }

    private function router(ContainerInterface $container): AppRouter
    {
        return AppRouter::create($this->pagesDir)->setContainer(new InjectorContainer($container));
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
        \http_response_code(200);

        \ob_start();

        try {
            $router->run();
        } finally {
            $output = (string) \ob_get_clean();
        }

        return $output;
    }
}
