<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Native;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Native\GoMiddlewareFile;

final class GoMiddlewareFileTest extends TestCase
{
    public function testDetectsATopLevelProxy(): void
    {
        $source = "package main\n\nimport \"net/http\"\n\nfunc Proxy(w http.ResponseWriter, r *http.Request) {}\n";

        self::assertTrue(GoMiddlewareFile::declaresProxy($source));
        self::assertTrue(GoMiddlewareFile::isMainPackage($source));
    }

    public function testIgnoresCommentedOutOrDifferentlyNamedFunctions(): void
    {
        self::assertFalse(GoMiddlewareFile::declaresProxy("package main\n// func Proxy(w http.ResponseWriter, r *http.Request) {}\n"));
        self::assertFalse(GoMiddlewareFile::declaresProxy("package main\nfunc proxy(w http.ResponseWriter, r *http.Request) {}\n"));
        self::assertFalse(GoMiddlewareFile::declaresProxy("package main\nfunc ProxyHandler(w http.ResponseWriter, r *http.Request) {}\n"));
        self::assertFalse(GoMiddlewareFile::declaresProxy("package main\nfunc GET(w http.ResponseWriter, r *http.Request) {}\n"));
    }
}
