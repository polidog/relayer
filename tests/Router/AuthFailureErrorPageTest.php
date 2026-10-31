<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Auth\AuthenticatorInterface;
use Polidog\Relayer\Auth\AuthGuard;
use Polidog\Relayer\Auth\Identity;
use Polidog\Relayer\Auth\UserProvider;
use Polidog\Relayer\I18n\LocaleResolver;
use Polidog\Relayer\I18n\Translator;
use Polidog\Relayer\InjectorContainer;
use Polidog\Relayer\Router\AppRouter;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Auth-guard 401 / 403 responses go through the same error path as
 * `$ctx->abort()`: `error.psx` inside the root layout, or the built-in
 * document. They used to be a status line with an empty body.
 *
 * Every test runs in its own process: the router skips the auth response once
 * headers are sent, and PHPUnit's progress output counts as sent. It also
 * lets each test declare the same page classes.
 */
final class AuthFailureErrorPageTest extends TestCase
{
    private const FUNCTION_PAGE = <<<'PHP'
        <?php
        use Polidog\Relayer\Router\Component\PageContext;
        use Polidog\UsePhp\Runtime\Element;

        return function (PageContext $ctx): Closure {
            %s

            return fn (): Element => new Element('p', [], ['secret page body']);
        };
        PHP;

    private const CLASS_PAGE = <<<'PHP'
        <?php
        namespace App\AuthFailure;

        use Polidog\Relayer\Auth\Auth;
        use Polidog\Relayer\Router\Component\PageComponent;
        use Polidog\UsePhp\Runtime\Element;

        #[Auth(%s)]
        final class GuardedPage extends PageComponent
        {
            public function render(): Element
            {
                return new Element('p', [], ['secret page body']);
            }
        }
        PHP;

    private const ERROR_PAGE = <<<'PHP'
        <?php
        namespace App\AuthFailure;

        use Polidog\Relayer\Router\Component\ErrorPageComponent;
        use Polidog\Relayer\Router\Component\PageContext;
        use Polidog\UsePhp\Runtime\Element;

        final class ErrorPage extends ErrorPageComponent
        {
            public function render(): Element
            {
                %s

                return new Element('h1', [], ['custom error ' . $this->getStatusCode() . ': ' . $this->getMessage()]);
            }
        }
        PHP;

    private const LAYOUT = <<<'PHP'
        <?php
        namespace App\AuthFailure;

        use Polidog\Relayer\Router\Component\PageContext;
        use Polidog\Relayer\Router\Layout\LayoutComponent;
        use Polidog\UsePhp\Runtime\Element;

        final class RootLayout extends LayoutComponent
        {
            public function render(): Element
            {
                %s

                return new Element('main', ['id' => 'root-layout'], [$this->getChildren()]);
            }
        }
        PHP;

    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/auth-failure-' . \bin2hex(\random_bytes(6));
        \mkdir($this->workDir, 0o777, true);
        \http_response_code(200);
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->workDir . '/*') ?: [] as $file) {
            \unlink($file);
        }
        \rmdir($this->workDir);
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    #[RunInSeparateProcess]
    public function testRequireAuthRoleFailureRendersErrorPageInRootLayout(): void
    {
        $this->writeFunctionPage("\$ctx->requireAuth(['admin']);");
        $this->writeErrorPage();
        $this->writeLayout();

        $output = $this->dispatch($this->memberIdentity());

        self::assertSame(403, \http_response_code());
        self::assertStringContainsString('custom error 403: Forbidden', $output);
        self::assertStringContainsString('id="root-layout"', $output);
        self::assertStringNotContainsString('secret page body', $output);
    }

    #[RunInSeparateProcess]
    public function testRequireAuthWithoutRedirectRendersErrorPageAs401(): void
    {
        $this->writeFunctionPage("\$ctx->requireAuth([], '');");
        $this->writeErrorPage();

        $output = $this->dispatch(null);

        self::assertSame(401, \http_response_code());
        self::assertStringContainsString('custom error 401: Unauthorized', $output);
        self::assertStringNotContainsString('secret page body', $output);
    }

    #[RunInSeparateProcess]
    public function testAuthAttributeRoleFailureRendersErrorPage(): void
    {
        // `#[Auth]` is evaluated in InjectorContainer, not in the page, and
        // used to `exit` there: this test's process would die before asserting.
        $this->writeClassPage("roles: ['admin']");
        $this->writeErrorPage();
        $this->writeLayout();

        $output = $this->dispatch($this->memberIdentity());

        self::assertSame(403, \http_response_code());
        self::assertStringContainsString('custom error 403: Forbidden', $output);
        self::assertStringContainsString('id="root-layout"', $output);
        self::assertStringNotContainsString('secret page body', $output);
    }

    #[RunInSeparateProcess]
    public function testAuthAttributeWithoutRedirectRendersErrorPageAs401(): void
    {
        $this->writeClassPage("redirectTo: ''");
        $this->writeErrorPage();

        $output = $this->dispatch(null);

        self::assertSame(401, \http_response_code());
        self::assertStringContainsString('custom error 401: Unauthorized', $output);
    }

    #[RunInSeparateProcess]
    public function testWithoutErrorPageTheBuiltInDocumentIsRendered(): void
    {
        $this->writeFunctionPage("\$ctx->requireAuth(['admin']);");

        $output = $this->dispatch($this->memberIdentity());

        self::assertSame(403, \http_response_code());
        self::assertStringContainsString('Forbidden', $output);
    }

    #[RunInSeparateProcess]
    public function testAuthAttributeWithoutErrorPageRendersTheBuiltInDocument(): void
    {
        $this->writeClassPage("roles: ['admin']");

        $output = $this->dispatch($this->memberIdentity());

        self::assertSame(403, \http_response_code());
        self::assertStringContainsString('Forbidden', $output);
    }

    #[RunInSeparateProcess]
    public function testGuardFailingInsideRootLayoutRendersErrorPageWithoutTheLayout(): void
    {
        // The page is public; the layout is what denies. The error page would
        // be wrapped in that same layout, which fails the same way again.
        $this->writeFunctionPage('');
        $this->writeErrorPage();
        $this->writeLayout("PageContext::current()->requireAuth(['admin']);");

        $output = $this->dispatch($this->memberIdentity());

        self::assertSame(403, \http_response_code());
        self::assertStringContainsString('custom error 403: Forbidden', $output);
        self::assertStringNotContainsString('id="root-layout"', $output);
        self::assertStringNotContainsString('secret page body', $output);
    }

    #[RunInSeparateProcess]
    public function testGuardFailingInsideErrorPageFallsBackToBuiltInDocument(): void
    {
        $this->writeFunctionPage("\$ctx->requireAuth(['admin']);");
        $this->writeErrorPage("PageContext::current()->requireAuth(['admin']);");

        $output = $this->dispatch($this->memberIdentity());

        self::assertSame(403, \http_response_code());
        self::assertStringContainsString('Forbidden', $output);
        self::assertStringNotContainsString('custom error', $output);
    }

    #[RunInSeparateProcess]
    public function testErrorPageGuardRaisingARedirectDoesNotChangeTheStatus(): void
    {
        $this->writeFunctionPage("\$ctx->requireAuth([], '');");
        $this->writeErrorPage('PageContext::current()->requireAuth();');

        $output = $this->dispatch(null);

        self::assertSame(401, \http_response_code());
        self::assertStringContainsString('Unauthorized', $output);
    }

    #[RunInSeparateProcess]
    public function testAbortRendersErrorPageWithoutLayoutWhenLayoutGuardFails(): void
    {
        // The containment lives in the shared error path, so an abort() gets
        // it too. This was an uncaught AuthorizationException before.
        $this->writeFunctionPage('$ctx->abort(410);');
        $this->writeErrorPage();
        $this->writeLayout("PageContext::current()->requireAuth(['admin']);");

        $output = $this->dispatch($this->memberIdentity());

        self::assertSame(410, \http_response_code());
        self::assertStringContainsString('custom error 410: Gone', $output);
    }

    #[RunInSeparateProcess]
    public function testErrorMessageIsLocalizedLikeAbort(): void
    {
        $this->writeFunctionPage("\$ctx->requireAuth(['admin']);");
        $this->writeErrorPage();

        $output = $this->dispatch($this->memberIdentity(), ['relayer.http.403' => 'No entry']);

        self::assertStringContainsString('custom error 403: No entry', $output);
    }

    #[RunInSeparateProcess]
    public function testAnonymousRedirectStillAnswersWithoutRenderingTheErrorPage(): void
    {
        $this->writeFunctionPage('$ctx->requireAuth();');
        $this->writeErrorPage();

        self::assertSame('', $this->dispatch(null));
    }

    #[RunInSeparateProcess]
    public function testAuthAttributeRedirectDecisionKeepsEnforceContract(): void
    {
        $this->writeClassPage('');

        require $this->workDir . '/page.php';

        // InjectorContainer exits on false, so this cannot go through run().
        self::assertFalse(AuthGuard::enforce('App\AuthFailure\GuardedPage', $this->authenticator(null), '/'));
    }

    /**
     * @param array<string, string> $messages
     */
    private function dispatch(?Identity $identity, array $messages = []): string
    {
        $builder = new ContainerBuilder();
        $builder->set(AuthenticatorInterface::class, $this->authenticator($identity));
        $builder->set(UserProvider::class, self::createStub(UserProvider::class));
        $builder->set(LocaleResolver::class, new LocaleResolver(['en'], 'en', pathPrefix: false));
        $builder->set(Translator::class, new Translator(['en' => $messages], 'en'));

        $app = AppRouter::create($this->workDir)->setContainer(new InjectorContainer($builder));
        \ob_start();

        try {
            $app->run();
        } finally {
            $output = (string) \ob_get_clean();
        }

        return $output;
    }

    private function memberIdentity(): Identity
    {
        return new Identity(1, 'Member', ['member']);
    }

    private function authenticator(?Identity $identity): AuthenticatorInterface
    {
        $auth = self::createStub(AuthenticatorInterface::class);
        $auth->method('user')->willReturn($identity);
        $auth->method('check')->willReturn(null !== $identity);
        $auth->method('hasAnyRole')->willReturnCallback(
            static fn (array $roles): bool => \array_any(
                $roles,
                static fn (mixed $role): bool => \in_array($role, $identity->roles ?? [], true),
            ),
        );

        return $auth;
    }

    private function writeFunctionPage(string $body): void
    {
        \file_put_contents($this->workDir . '/page.php', \sprintf(self::FUNCTION_PAGE, $body));
    }

    private function writeClassPage(string $authArguments): void
    {
        \file_put_contents($this->workDir . '/page.php', \sprintf(self::CLASS_PAGE, $authArguments));
    }

    private function writeErrorPage(string $renderPrelude = ''): void
    {
        \file_put_contents($this->workDir . '/error.php', \sprintf(self::ERROR_PAGE, $renderPrelude));
    }

    private function writeLayout(string $renderPrelude = ''): void
    {
        \file_put_contents($this->workDir . '/layout.php', \sprintf(self::LAYOUT, $renderPrelude));
    }
}
