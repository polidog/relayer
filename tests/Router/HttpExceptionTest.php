<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\HttpException;

final class HttpExceptionTest extends TestCase
{
    public function testDerivesStandardReasonPhrase(): void
    {
        $exception = new HttpException(403);

        self::assertSame(403, $exception->status);
        self::assertSame('Forbidden', $exception->reason);
        self::assertSame('HTTP 403 Forbidden', $exception->getMessage());
    }

    public function testExplicitReasonOverridesTheMap(): void
    {
        $exception = new HttpException(404, 'No such article');

        self::assertSame(404, $exception->status);
        self::assertSame('No such article', $exception->reason);
    }

    public function testUnknownClientStatusDegradesToGenericLabel(): void
    {
        self::assertSame('Client Error', HttpException::reasonPhrase(499));
    }

    public function testUnknownServerStatusDegradesToGenericLabel(): void
    {
        self::assertSame('Server Error', HttpException::reasonPhrase(599));
    }
}
