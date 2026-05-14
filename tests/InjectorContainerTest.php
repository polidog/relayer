<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\InjectorContainer;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class InjectorContainerTest extends TestCase
{
    public function testHasReturnsTrueForLoadableClassEvenWhenUnregistered(): void
    {
        $container = new InjectorContainer($this->compile(static function (): void {}));

        self::assertTrue($container->has(Fixtures\PlainService::class));
        self::assertFalse($container->has('Nope\\DoesNotExist'));
    }

    public function testGetAutoWiresUnregisteredClass(): void
    {
        $container = new InjectorContainer($this->compile(static function (): void {}));

        $service = $container->get(Fixtures\PlainService::class);

        self::assertInstanceOf(Fixtures\PlainService::class, $service);
    }

    public function testGetResolvesRegisteredServiceFromContainer(): void
    {
        $container = new InjectorContainer($this->compile(static function (ContainerBuilder $c): void {
            $c->register(Fixtures\PlainService::class)
                ->setAutowired(true)
                ->setPublic(true);
            $c->register(Fixtures\ServiceWithDependency::class)
                ->setAutowired(true)
                ->setPublic(true);
        }));

        $service = $container->get(Fixtures\ServiceWithDependency::class);

        self::assertInstanceOf(Fixtures\ServiceWithDependency::class, $service);
        self::assertInstanceOf(Fixtures\PlainService::class, $service->inner);
    }

    public function testGetAutoWiresUnregisteredClassWithRegisteredDependency(): void
    {
        $container = new InjectorContainer($this->compile(static function (ContainerBuilder $c): void {
            $c->register(Fixtures\PlainService::class)
                ->setAutowired(true)
                ->setPublic(true);
        }));

        $service = $container->get(Fixtures\ServiceWithDependency::class);

        self::assertInstanceOf(Fixtures\ServiceWithDependency::class, $service);
        self::assertInstanceOf(Fixtures\PlainService::class, $service->inner);
    }

    public function testGetReturnsCachedPageComponent(): void
    {
        $container = new InjectorContainer($this->compile(static function (): void {}));

        $page = $container->get(Fixtures\CachedPage::class);

        self::assertInstanceOf(Fixtures\CachedPage::class, $page);
    }

    public function testGetReturnsUncachedPageComponent(): void
    {
        $container = new InjectorContainer($this->compile(static function (): void {}));

        $page = $container->get(Fixtures\UncachedPage::class);

        self::assertInstanceOf(Fixtures\UncachedPage::class, $page);
    }

    /**
     * @param callable(ContainerBuilder): void $configure
     */
    private function compile(callable $configure): ContainerBuilder
    {
        $builder = new ContainerBuilder();
        $configure($builder);
        $builder->compile();

        return $builder;
    }
}
