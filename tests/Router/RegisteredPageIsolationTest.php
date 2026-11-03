<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\AppConfigurator;
use Polidog\Relayer\Di\ContainerFactory;
use Polidog\Relayer\InjectorContainer;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Tests\Fixtures\PlainService;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;

/**
 * A page, layout or error page registered in the container built by
 * {@see ContainerFactory} must be a new object for every request — including
 * the objects its constructor created for itself (a form, a DTO), which a
 * shallow `clone` of one shared service would still hand from one visitor to
 * the next. The services injected into it stay the container's services.
 * Checked on the live container and on the dumped one prod boots from
 * (`relayer container:compile`).
 */
final class RegisteredPageIsolationTest extends TestCase
{
    private string $workDir;

    private string $namespace;

    protected function setUp(): void
    {
        $token = \bin2hex(\random_bytes(6));
        $this->workDir = \sys_get_temp_dir() . '/registered-page-' . $token;
        \mkdir($this->workDir . '/form', 0o777, true);

        // Unique namespace per test: the files are written per test and
        // loaded with require_once.
        $this->namespace = 'Polidog\Relayer\Tests\Tmp\RegisteredPage\T' . $token;

        \file_put_contents($this->workDir . '/layout.php', \sprintf(<<<'PHP'
            <?php
            declare(strict_types=1);
            namespace %s;
            use ArrayObject;
            use Polidog\Relayer\Router\Layout\LayoutComponent;
            use Polidog\Relayer\Tests\Fixtures\PlainService;
            use Polidog\Rosanna\Runtime\Element;
            final class RootLayout extends LayoutComponent
            {
                /** @var ArrayObject<int, string> */
                public readonly ArrayObject $seen;

                public function __construct(public readonly PlainService $service)
                {
                    $this->seen = new ArrayObject();
                }

                public function render(): Element
                {
                    $this->seen->append('render');

                    return new Element('main', [], [
                        'layout-seen=' . \count($this->seen)
                        . ';layout-service=' . \spl_object_id($this->service) . ';',
                        $this->getChildren(),
                    ]);
                }
            }
            PHP, $this->namespace));

        \file_put_contents($this->workDir . '/form/page.php', \sprintf(<<<'PHP'
            <?php
            declare(strict_types=1);
            namespace %s;
            use ArrayObject;
            use Polidog\Relayer\Router\Component\PageComponent;
            use Polidog\Relayer\Tests\Fixtures\PlainService;
            use Polidog\Rosanna\Runtime\Element;
            final class FormPage extends PageComponent
            {
                /** @var ArrayObject<string, string> */
                public readonly ArrayObject $form;

                public function __construct(public readonly PlainService $service)
                {
                    $this->form = new ArrayObject();
                }

                public function render(): Element
                {
                    $name = $this->getQuery('name');
                    if (null !== $name) {
                        $this->form['name'] = $name;
                    }

                    return new Element('p', [], [
                        'form-name=' . ($this->form['name'] ?? '-')
                        . ';page-service=' . \spl_object_id($this->service) . ';',
                    ]);
                }
            }
            PHP, $this->namespace));

        \file_put_contents($this->workDir . '/error.php', \sprintf(<<<'PHP'
            <?php
            declare(strict_types=1);
            namespace %s;
            use ArrayObject;
            use Polidog\Relayer\Router\Component\ErrorPageComponent;
            use Polidog\Rosanna\Runtime\Element;
            final class ErrorPage extends ErrorPageComponent
            {
                /** @var ArrayObject<int, string> */
                public readonly ArrayObject $seen;

                public function __construct()
                {
                    $this->seen = new ArrayObject();
                }

                public function render(): Element
                {
                    $this->seen->append('render');

                    return new Element('p', [], ['error-seen=' . \count($this->seen) . ';']);
                }
            }
            PHP, $this->namespace));

        // The container has to see the classes to autowire them.
        require_once $this->workDir . '/layout.php';

        require_once $this->workDir . '/form/page.php';

        require_once $this->workDir . '/error.php';

        \http_response_code(200);
        $_GET = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->workDir . '/*.php') ?: [] as $file) {
            @\unlink($file);
        }
        @\unlink($this->workDir . '/form/page.php');
        @\rmdir($this->workDir . '/form');
        @\rmdir($this->workDir);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
        $_GET = [];
        $_POST = [];
    }

    #[DataProvider('containers')]
    public function testConstructorStateOfThePageDoesNotReachTheNextVisitor(bool $dumped, bool $explicitlyShared): void
    {
        $router = $this->router($this->container($dumped, $explicitlyShared));

        self::assertStringContainsString('form-name=alice;', $this->dispatch($router, '/form?name=alice'));

        $next = $this->dispatch($router, '/form');

        self::assertStringContainsString('form-name=-;', $next);
        self::assertStringNotContainsString('alice', $next);
    }

    #[DataProvider('containers')]
    public function testConstructorStateOfTheLayoutIsFreshForEveryRequest(bool $dumped, bool $explicitlyShared): void
    {
        $router = $this->router($this->container($dumped, $explicitlyShared));

        $this->dispatch($router, '/form');
        $second = $this->dispatch($router, '/form');

        self::assertStringContainsString('layout-seen=1;', $second);
    }

    #[DataProvider('containers')]
    public function testConstructorStateOfTheErrorPageIsFreshForEveryRequest(bool $dumped, bool $explicitlyShared): void
    {
        $router = $this->router($this->container($dumped, $explicitlyShared));

        $this->dispatch($router, '/missing');
        $second = $this->dispatch($router, '/missing');

        self::assertSame(404, \http_response_code());
        self::assertStringContainsString('error-seen=1;', $second);
    }

    #[DataProvider('containers')]
    public function testInjectedServicesAreStillTheContainerServices(bool $dumped, bool $explicitlyShared): void
    {
        $container = $this->container($dumped, $explicitlyShared);
        $router = $this->router($container);
        $service = \spl_object_id($container->get(PlainService::class));

        foreach (['/form?name=alice', '/form'] as $uri) {
            $output = $this->dispatch($router, $uri);

            self::assertStringContainsString(';page-service=' . $service . ';', $output);
            self::assertStringContainsString(';layout-service=' . $service . ';', $output);
        }
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function containers(): iterable
    {
        yield 'live container' => [false, false];

        yield 'dumped container' => [true, false];

        yield 'live container, registered as shared on purpose' => [false, true];

        yield 'dumped container, registered as shared on purpose' => [true, true];
    }

    public function testPagesLayoutsAndErrorPagesAreNotSharedServices(): void
    {
        $container = $this->build(forDump: false, explicitlyShared: false);

        foreach (['FormPage', 'RootLayout', 'ErrorPage'] as $class) {
            self::assertFalse($container->getDefinition($this->namespace . '\\' . $class)->isShared(), $class);
        }
        self::assertTrue($container->getDefinition(PlainService::class)->isShared());
    }

    private function container(bool $dumped, bool $explicitlyShared): ContainerInterface
    {
        $builder = $this->build($dumped, $explicitlyShared);

        if (!$dumped) {
            return $builder;
        }

        $className = 'RegisteredPageDump_' . \bin2hex(\random_bytes(4));
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

    private function build(bool $forDump, bool $explicitlyShared): ContainerBuilder
    {
        $configurator = new class($this->workDir, $this->namespace, $explicitlyShared) extends AppConfigurator {
            public function __construct(string $projectRoot, private readonly string $namespace, private readonly bool $explicitlyShared)
            {
                parent::__construct($projectRoot);
            }

            public function configure(ContainerBuilder $container): void
            {
                $container->register(PlainService::class, PlainService::class);
                foreach (['FormPage', 'RootLayout', 'ErrorPage'] as $class) {
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
        return AppRouter::create($this->workDir)->setContainer(new InjectorContainer($container));
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
