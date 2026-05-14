<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;

final class PersonalizeDispatchTest extends TestCase
{
    private string $projectRoot;
    private string $pagesDir;
    private string $personalizeDir;

    protected function setUp(): void
    {
        $this->projectRoot = \sys_get_temp_dir() . '/relayer-personalize-' . \uniqid();
        $this->pagesDir = $this->projectRoot . '/src/Pages';
        $this->personalizeDir = $this->projectRoot . '/src/Personalize';
        \mkdir($this->pagesDir, 0o777, true);
        \mkdir($this->personalizeDir, 0o777, true);

        // A stub page lives in pagesDir so the router has a valid `/`. The
        // personalize tests never hit it; we just need the app dir to load.
        \file_put_contents(
            $this->pagesDir . '/page.psx',
            "<?php\n"
            . "use Polidog\\Relayer\\Router\\Component\\PageContext;\n"
            . "use Polidog\\UsePhp\\Runtime\\Element;\n"
            . "return fn(PageContext \$ctx) => fn(): Element => new Element('p', [], ['stub']);\n",
        );
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->projectRoot);
        unset(
            $_SERVER['REQUEST_URI'],
            $_SERVER['REQUEST_METHOD'],
        );
    }

    public function testFragmentEndpointReturnsBareHtmlWithoutDocumentChrome(): void
    {
        \file_put_contents(
            $this->personalizeDir . '/user-header.psx',
            <<<'PSX'
                <?php
                use Polidog\Relayer\Personalization\PersonalizeContext;
                use Polidog\UsePhp\Runtime\Element;

                return function (PersonalizeContext $ctx): Element {
                    return new Element('span', ['data-id' => $ctx->id], ['hello']);
                };
                PSX,
        );

        $output = $this->runApp('/_relayer/personalize/user-header');

        self::assertStringNotContainsString('<!DOCTYPE', $output);
        self::assertStringNotContainsString('<html', $output);
        self::assertStringNotContainsString('<head', $output);
        self::assertStringNotContainsString('data-usephp=', $output);
        self::assertStringContainsString('<span data-id="user-header">hello</span>', $output);
    }

    public function testFactoryClosureFormIsSupported(): void
    {
        \file_put_contents(
            $this->personalizeDir . '/two-step.psx',
            <<<'PSX'
                <?php
                use Polidog\Relayer\Personalization\PersonalizeContext;
                use Polidog\UsePhp\Runtime\Element;

                return function (PersonalizeContext $ctx): Closure {
                    return function () use ($ctx): Element {
                        return new Element('div', [], ['from-render-closure']);
                    };
                };
                PSX,
        );

        $output = $this->runApp('/_relayer/personalize/two-step');

        self::assertStringContainsString('from-render-closure', $output);
    }

    public function testUnknownIdReturns404(): void
    {
        $output = $this->runApp('/_relayer/personalize/missing-handler');

        self::assertSame(404, \http_response_code());
        // No body for the missing fragment — the SSR fallback covers anonymous UX.
        self::assertSame('', $output);
    }

    public function testInvalidIdShapeReturns404(): void
    {
        // The early branch only fires on the literal prefix, but inside it
        // anything failing the [a-zA-Z0-9._-]+ shape must 404 — verifies
        // we don't accidentally walk outside src/Personalize/.
        $output = $this->runApp('/_relayer/personalize/has%20space');

        self::assertSame(404, \http_response_code());
        self::assertSame('', $output);
    }

    public function testEmptyIdReturns404(): void
    {
        $output = $this->runApp('/_relayer/personalize/');

        self::assertSame(404, \http_response_code());
        self::assertSame('', $output);
    }

    public function testDisablingPersonalizationShortCircuitsTo404(): void
    {
        \file_put_contents(
            $this->personalizeDir . '/user-header.psx',
            <<<'PSX'
                <?php
                use Polidog\Relayer\Personalization\PersonalizeContext;
                use Polidog\UsePhp\Runtime\Element;

                return fn(PersonalizeContext $ctx): Element => new Element('span', [], ['should-not-show']);
                PSX,
        );

        $output = $this->runApp('/_relayer/personalize/user-header', disablePersonalization: true);

        self::assertSame(404, \http_response_code());
        self::assertSame('', $output);
    }

    private function runApp(string $path, bool $disablePersonalization = false): string
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        \http_response_code(200);

        $app = AppRouter::create($this->pagesDir, autoCompilePsx: true);
        if ($disablePersonalization) {
            $app->disablePersonalization();
        }

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
