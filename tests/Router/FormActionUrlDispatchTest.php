<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\I18n\LocaleResolver;
use Polidog\Relayer\I18n\Translator;
use Polidog\Relayer\InjectorContainer;
use Polidog\Relayer\Router\AppRouter;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A form posts back to the URL it was rendered for, but only ever as an
 * origin-relative path and query: whatever host the request target names, the
 * CSRF token must not leave this origin (#119).
 */
final class FormActionUrlDispatchTest extends TestCase
{
    private const PAGE = <<<'PHP'
        <?php
        declare(strict_types=1);
        use Polidog\Relayer\Router\Component\PageContext;
        use Polidog\Rosanna\Runtime\Element;
        return function (PageContext $ctx): Closure {
            $save = $ctx->action('save', static function (): void {});
            return static fn (): Element => new Element('form', ['action' => $save], ['page:%s']);
        };
        PHP;

    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/relayer-form-action-' . \bin2hex(\random_bytes(6));
        \mkdir($this->workDir . '/admin', 0o777, true);

        \file_put_contents($this->workDir . '/page.php', \sprintf(self::PAGE, 'root'));
        \file_put_contents($this->workDir . '/admin/page.php', \sprintf(self::PAGE, 'admin'));
        // Most host-looking targets do not match a route; the error page
        // renders a form too, so its action is checked all the same.
        \file_put_contents($this->workDir . '/error.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            namespace Polidog\Relayer\Tests\Tmp\FormActionUrl;
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

    #[DataProvider('provideTheFormPostsToAnOriginRelativeUrlCases')]
    public function testTheFormPostsToAnOriginRelativeUrl(string $uri, string $page, string $action): void
    {
        $html = $this->dispatch(AppRouter::create($this->workDir), $uri);

        self::assertStringContainsString('page:' . $page, $html);
        self::assertSame($action, self::formAction($html));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideTheFormPostsToAnOriginRelativeUrlCases(): iterable
    {
        yield 'absolute form' => ['http://other.example/admin?q=1', 'admin', '/admin?q=1'];

        yield 'absolute form, upper-case scheme' => ['HTTPS://other.example/admin', 'admin', '/admin'];

        yield 'absolute form with userinfo and port' => ['http://u:p@other.example:8080/admin?q=1&r=2', 'admin', '/admin?q=1&r=2'];

        yield 'absolute form without a path' => ['http://other.example', 'root', '/'];

        yield 'absolute form with only a query' => ['http://other.example?q=1', 'root', '/?q=1'];

        yield 'origin form keeps its query' => ['/admin?next=http://other.example/', 'admin', '/admin?next=http://other.example/'];
    }

    #[DataProvider('provideTheFormNeverPostsToTheHostTheTargetLooksLikeCases')]
    public function testTheFormNeverPostsToTheHostTheTargetLooksLike(string $uri): void
    {
        $action = self::formAction($this->dispatch(AppRouter::create($this->workDir), $uri));

        // A browser drops tabs and newlines anywhere in a URL before parsing it.
        $asParsed = \str_replace(["\t", "\n", "\r"], '', $action);

        self::assertMatchesRegularExpression('#^/(?![/\\\])#', $asParsed);
    }

    /**
     * Each of these would send a browser to `other.example`, or does once the
     * browser drops the tab.
     *
     * @return iterable<string, array{string}>
     */
    public static function provideTheFormNeverPostsToTheHostTheTargetLooksLikeCases(): iterable
    {
        yield 'leading double slash' => ['//other.example/admin'];

        yield 'slash and backslash' => ['/\other.example/admin'];

        yield 'absolute form with a protocol-relative path' => ['http://evil.example//other.example/admin'];

        yield 'absolute form with a backslash' => ['http://evil.example\other.example/admin'];

        yield 'scheme with one slash' => ['http:/other.example/admin'];

        yield 'scheme with backslashes' => ['http:\\\other.example\admin'];

        yield 'tab between the slashes' => ["/\t/other.example/admin"];
    }

    public function testTheLocalePrefixTheBrowserRequestedIsKept(): void
    {
        $builder = new ContainerBuilder();
        $builder->set(LocaleResolver::class, new LocaleResolver(['en', 'ja'], 'en'));
        $builder->set(Translator::class, Translator::framework());

        $app = AppRouter::create($this->workDir)->setContainer(new InjectorContainer($builder));
        $html = $this->dispatch($app, 'http://other.example/ja/admin?q=1');

        self::assertStringContainsString('page:admin', $html);
        self::assertSame('/ja/admin?q=1', self::formAction($html));
    }

    private function dispatch(AppRouter $app, string $uri): string
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = $uri;

        \http_response_code(200);
        \ob_start();
        $app->run();

        return (string) \ob_get_clean();
    }

    private static function formAction(string $html): string
    {
        if (1 !== \preg_match('#<form action="([^"]*)"#', $html, $match)) {
            self::fail('No form in: ' . $html);
        }

        return \html_entity_decode($match[1], \ENT_QUOTES | \ENT_HTML5);
    }
}
