<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Routing\Router;

/**
 * Colons and a leading `//` are ordinary bytes in a request line, but
 * `parse_url()` reads them as a port and a host.
 */
final class UnusualPathDispatchTest extends TestCase
{
    private const PAGE = <<<'PHP'
        <?php
        declare(strict_types=1);
        use Polidog\Relayer\Router\Component\PageContext;
        use Polidog\Rosanna\Runtime\Element;
        return function (PageContext $ctx): Closure {
            $save = $ctx->action('save', static function (): void {});
            return static fn (): Element => new Element('form', ['action' => $save], ['page:%s:' . ($ctx->params['id'] ?? '')]);
        };
        PHP;

    private string $workDir;

    private int $status = 0;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/relayer-paths-' . \bin2hex(\random_bytes(6));
        \mkdir($this->workDir . '/admin', 0o777, true);
        \mkdir($this->workDir . '/x/[id]', 0o777, true);

        \file_put_contents($this->workDir . '/page.php', \sprintf(self::PAGE, 'root'));
        \file_put_contents($this->workDir . '/admin/page.php', \sprintf(self::PAGE, 'admin'));
        \file_put_contents($this->workDir . '/x/[id]/page.php', \sprintf(self::PAGE, 'x'));
        \file_put_contents($this->workDir . '/error.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Polidog\Relayer\Tests\Tmp\UnusualPath;
            use Polidog\Relayer\Router\Component\ErrorPageComponent;
            use Polidog\Relayer\Router\Form\FormAction;
            use Polidog\Rosanna\Runtime\Element;
            if (!class_exists(ErrorPage::class, false)) {
                final class ErrorPage extends ErrorPageComponent
                {
                    public function render(): Element
                    {
                        return new Element('form', ['action' => FormAction::create('X', 'y')], ['error']);
                    }
                }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->workDir));
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    public function testAColonInAPathReachesItsRoute(): void
    {
        self::assertStringContainsString('page:x:a:80', $this->dispatch('/x/a:80'));
    }

    public function testRouterMatchesAColonInAnUnencodedPath(): void
    {
        self::assertSame('/x/[id]', Router::create($this->workDir)->match('/x/a:80')?->route->pattern);
    }

    #[DataProvider('leadingSlashPaths')]
    public function testALeadingDoubleSlashIsNotFound(string $path): void
    {
        $html = $this->dispatch($path);

        self::assertSame(404, $this->status);
        self::assertStringNotContainsString('page:', $html);
    }

    #[DataProvider('leadingSlashPaths')]
    public function testAFormNeverPostsToTheHostAPathLooksLike(string $path): void
    {
        self::assertMatchesRegularExpression('#<form action="/(?!/)#', $this->dispatch($path));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function leadingSlashPaths(): iterable
    {
        yield 'looks like /admin' => ['//admin'];

        yield 'looks like a host and /' => ['//evil.example'];

        yield 'looks like a host and /admin' => ['//evil.example/admin'];

        yield 'three slashes' => ['///admin'];
    }

    private function dispatch(string $uri): string
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $uri;

        \http_response_code(200);
        \ob_start();
        AppRouter::create($this->workDir)->run();
        $html = (string) \ob_get_clean();

        $status = \http_response_code();
        $this->status = \is_int($status) ? $status : 0;

        return $html;
    }
}
