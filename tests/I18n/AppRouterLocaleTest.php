<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\I18n;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\I18n\LocaleResolver;
use Polidog\Relayer\I18n\Translator;
use Polidog\Relayer\I18n\Translators;
use Polidog\Relayer\Router\AppRouter;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

final class AppRouterLocaleTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/approuter-i18n-' . \uniqid();
        \mkdir($this->workDir, 0o777, true);
        \http_response_code(200);
        $_POST = [];
        $_GET = [];
        Translators::reset();
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workDir);
        $_POST = [];
        $_GET = [];
        // resolveLocale() publishes the request translator as the ambient
        // one; clear it so a non-English locale cannot leak into later
        // (e.g. Validation) tests.
        Translators::reset();
    }

    public function testPathPrefixIsStrippedAndRouteIsServed(): void
    {
        $this->writeRoute('users', <<<'PHP'
            use Polidog\Relayer\Http\Response;

            return ['GET' => static fn (): Response => Response::json(['ok' => true])];
            PHP);

        $app = AppRouter::create($this->workDir);
        $app->setContainer($this->container());

        $output = $this->dispatch($app, '/ja/users', 'GET');

        self::assertSame('{"ok":true}', $output);
        self::assertSame(200, \http_response_code());
        self::assertSame('ja', Translators::default()->getLocale());
    }

    public function testFrameworkErrorJsonIsLocalized(): void
    {
        $this->writeRoute('users', <<<'PHP'
            use Polidog\Relayer\Http\Response;

            return ['GET' => static fn (): Response => Response::json([])];
            PHP);

        $app = AppRouter::create($this->workDir);
        $app->setContainer($this->container());

        $output = $this->dispatch($app, '/ja/users', 'DELETE');

        self::assertSame(405, \http_response_code());

        $decoded = \json_decode($output, true);
        self::assertIsArray($decoded);
        self::assertSame('許可されていないメソッドです', $decoded['error']);
    }

    public function testNoLocaleResolverKeepsPreI18nBehavior(): void
    {
        $this->writeRoute('users', <<<'PHP'
            use Polidog\Relayer\Http\Response;

            return ['GET' => static fn (): Response => Response::json(['ok' => true])];
            PHP);

        // No container at all → resolveLocale() is a no-op, so a
        // locale-looking prefix is just an unknown path (404), exactly as
        // before i18n existed.
        $output = $this->dispatch(AppRouter::create($this->workDir), '/ja/users', 'GET');

        self::assertSame(404, \http_response_code());
        self::assertStringNotContainsString('"ok":true', $output);
    }

    public function testEnglishLocaleErrorJsonIsByteIdenticalToPreI18n(): void
    {
        $this->writeRoute('users', <<<'PHP'
            use Polidog\Relayer\Http\Response;

            return ['GET' => static fn (): Response => Response::json([])];
            PHP);

        $app = AppRouter::create($this->workDir);
        $app->setContainer($this->container());

        // Default locale (no prefix, no headers) → English, unchanged body.
        $output = $this->dispatch($app, '/users', 'DELETE');

        self::assertSame(405, \http_response_code());
        self::assertSame('{"error":"Method Not Allowed"}', $output);
    }

    private function container(): ContainerInterface
    {
        $resolver = new LocaleResolver(['en', 'ja'], 'en', true, 'locale', null);
        $translator = Translator::framework();

        return new class($resolver, $translator) implements ContainerInterface {
            public function __construct(
                private readonly LocaleResolver $resolver,
                private readonly Translator $translator,
            ) {}

            public function has(string $id): bool
            {
                return LocaleResolver::class === $id || Translator::class === $id;
            }

            public function get(string $id): mixed
            {
                return match ($id) {
                    LocaleResolver::class => $this->resolver,
                    Translator::class => $this->translator,
                    default => throw new class('Unknown service: ' . $id) extends RuntimeException implements NotFoundExceptionInterface {},
                };
            }
        };
    }

    private function writeRoute(string $segment, string $body): void
    {
        \mkdir($this->workDir . '/' . $segment, 0o777, true);
        \file_put_contents(
            $this->workDir . '/' . $segment . '/route.php',
            "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n",
        );
    }

    private function dispatch(AppRouter $app, string $path, string $method): string
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = $method;
        \ob_start();

        try {
            $app->run();
        } finally {
            $output = (string) \ob_get_clean();
        }

        return $output;
    }

    private function rmrf(string $path): void
    {
        if (!\file_exists($path)) {
            return;
        }
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);

            return;
        }
        $entries = \scandir($path);
        if (false === $entries) {
            return;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $this->rmrf($path . '/' . $entry);
        }
        @\rmdir($path);
    }
}
