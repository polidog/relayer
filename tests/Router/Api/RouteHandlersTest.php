<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Api;

use Closure;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Api\RouteHandlers;
use RuntimeException;

final class RouteHandlersTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $file) {
            @\unlink($file);
        }
        $this->tmpFiles = [];
    }

    public function testFromFileBuildsMapAndNormalizesMethodCase(): void
    {
        $file = $this->writeRoute(
            "return ['get' => fn () => ['a' => 1], 'POST' => fn () => ['b' => 2]];",
        );

        $handlers = RouteHandlers::fromFile($file);

        self::assertInstanceOf(Closure::class, $handlers->handlerFor('GET'));
        // Lookup is case-insensitive on both the key and the request method.
        self::assertInstanceOf(Closure::class, $handlers->handlerFor('get'));
        self::assertInstanceOf(Closure::class, $handlers->handlerFor('post'));
        self::assertNull($handlers->handlerFor('DELETE'));
    }

    public function testAllowedMethodsReturnsSortedUpperCaseList(): void
    {
        $file = $this->writeRoute(
            "return ['post' => fn () => null, 'GET' => fn () => null, 'delete' => fn () => null];",
        );

        $handlers = RouteHandlers::fromFile($file);

        self::assertSame(['DELETE', 'GET', 'POST'], $handlers->allowedMethods());
    }

    public function testEffectiveAllowedMethodsSynthesizesOptionsAndHeadWhenGetPresent(): void
    {
        $file = $this->writeRoute(
            "return ['GET' => fn () => null, 'post' => fn () => null];",
        );

        $handlers = RouteHandlers::fromFile($file);

        // Declared list stays authored-only…
        self::assertSame(['GET', 'POST'], $handlers->allowedMethods());
        // …effective list adds OPTIONS (always) + HEAD (GET present).
        self::assertSame(['GET', 'HEAD', 'OPTIONS', 'POST'], $handlers->effectiveAllowedMethods());
    }

    public function testEffectiveAllowedMethodsHasNoHeadWithoutGetAndDoesNotDuplicate(): void
    {
        $file = $this->writeRoute(
            "return ['POST' => fn () => null, 'options' => fn () => null];",
        );

        $handlers = RouteHandlers::fromFile($file);

        // No GET ⇒ no synthesized HEAD; an explicit OPTIONS is not doubled.
        self::assertSame(['OPTIONS', 'POST'], $handlers->effectiveAllowedMethods());
    }

    public function testNonArrayReturnIsRejected(): void
    {
        $file = $this->writeRoute('return fn () => 1;');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must return a non-empty array');
        RouteHandlers::fromFile($file);
    }

    public function testEmptyArrayIsRejected(): void
    {
        $file = $this->writeRoute('return [];');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must return a non-empty array');
        RouteHandlers::fromFile($file);
    }

    public function testNonClosureHandlerIsRejected(): void
    {
        $file = $this->writeRoute("return ['GET' => 'not-a-closure'];");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('handler for "GET" must be a Closure');
        RouteHandlers::fromFile($file);
    }

    public function testNonStringMethodKeyIsRejected(): void
    {
        $file = $this->writeRoute('return [0 => fn () => null];');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-string method key');
        RouteHandlers::fromFile($file);
    }

    private function writeRoute(string $body): string
    {
        $file = \sys_get_temp_dir() . '/relayer-route-' . \uniqid() . '.php';
        \file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n");
        $this->tmpFiles[] = $file;

        return $file;
    }
}
