<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Native;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Native\GoRouteFile;

final class GoRouteFileTest extends TestCase
{
    public function testDeclaredMethodsAreExtractedAndSorted(): void
    {
        $source = <<<'GO'
            package main

            import "net/http"

            func POST(w http.ResponseWriter, r *http.Request) {}
            func GET(w http.ResponseWriter, r *http.Request) {}
            func DELETE (w http.ResponseWriter, r *http.Request) {}
            GO;

        self::assertSame(['DELETE', 'GET', 'POST'], GoRouteFile::declaredMethods($source));
    }

    public function testOnlyTopLevelHandlersNamedAfterMethodsCount(): void
    {
        $source = <<<'GO'
            package main

            import "net/http"

            // A commented-out handler is not declared:
            // func DELETE(w http.ResponseWriter, r *http.Request) {}

            /* nor one in a block comment:
            func PUT(w http.ResponseWriter, r *http.Request) {}
            */

            func helper() {
                // an indented one is not top-level
                func PATCH() {}
            }

            func GETTER() {}
            func get(w http.ResponseWriter, r *http.Request) {}

            func GET(w http.ResponseWriter, r *http.Request) {}
            GO;

        self::assertSame(['GET'], GoRouteFile::declaredMethods($source));
    }

    public function testNoHandlersYieldsEmptyList(): void
    {
        self::assertSame([], GoRouteFile::declaredMethods("package main\n\nfunc main() {}\n"));
    }

    public function testEffectiveMethodsAddOptionsAlwaysAndHeadWithGet(): void
    {
        self::assertSame(['GET', 'HEAD', 'OPTIONS', 'POST'], GoRouteFile::effectiveAllowedMethods(['GET', 'POST']));
        self::assertSame(['OPTIONS', 'POST'], GoRouteFile::effectiveAllowedMethods(['POST']));
        // Explicit HEAD / OPTIONS are not duplicated.
        self::assertSame(['GET', 'HEAD', 'OPTIONS'], GoRouteFile::effectiveAllowedMethods(['GET', 'HEAD', 'OPTIONS']));
    }

    public function testPackageMainIsDetected(): void
    {
        self::assertTrue(GoRouteFile::isMainPackage("// doc\npackage main\n"));
        self::assertFalse(GoRouteFile::isMainPackage("package handler\n"));
        self::assertFalse(GoRouteFile::isMainPackage("// package main\npackage other\n"));
        self::assertFalse(GoRouteFile::isMainPackage("package mainly\n"));
    }
}
