<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Native;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Native\NativeSlug;

final class NativeSlugTest extends TestCase
{
    #[DataProvider('provideSlugIsDeterministicAndFileNameSafeCases')]
    public function testSlugIsDeterministicAndFileNameSafe(string $pattern, string $expected): void
    {
        self::assertSame($expected, NativeSlug::forPattern($pattern));
        self::assertStringNotContainsString('/', NativeSlug::forPattern($pattern));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideSlugIsDeterministicAndFileNameSafeCases(): iterable
    {
        yield 'single segment' => ['/api/hello', 'api__hello'];

        yield 'dynamic segment keeps brackets' => ['/api/users/[id]', 'api__users__[id]'];

        yield 'hyphen and dot survive' => ['/api/hello-go/v1.2', 'api__hello-go__v1.2'];

        yield 'root has a fixed name' => ['/', NativeSlug::ROOT];

        yield 'anything else folds to underscore' => ['/a b/ü', 'a_b____'];
    }

    public function testModuleNameFoldsBracketsForGoModulePathRules(): void
    {
        self::assertSame('relayer_native_api__hello', NativeSlug::moduleName('api__hello'));
        self::assertSame('relayer_native_api__users___id_', NativeSlug::moduleName('api__users__[id]'));
    }
}
