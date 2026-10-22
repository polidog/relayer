<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\AppRouter;
use Polidog\Relayer\Router\Form\CsrfToken;
use Polidog\UsePhp\Runtime\ComponentState;

/**
 * useState `setState` POSTs must carry the session-bound CSRF token.
 *
 * The `_usephp_component` id is rendered into the page and is not a secret,
 * so on its own it cannot authenticate a state write: without the token a
 * cross-site form could rewrite the victim's page state. AppRouter rejects
 * such POSTs with 403 before touching state, and LayoutRenderer embeds the
 * token into every setState form so legitimate submissions — both the
 * no-JS PRG POST and usePHP's fetch-based partial update, which posts the
 * form's own FormData — keep working.
 *
 * Each test runs in its own process because CsrfToken / session-backed
 * state call session_start().
 */
final class StateActionCsrfTest extends TestCase
{
    private const string COMPONENT_ID = 'page:/';
    private const string SET_STATE = '{"type":"setState","payload":{"index":0,"value":"forged"}}';

    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/state-csrf-' . \uniqid();
        \mkdir($this->workDir, 0o777, true);
        $_POST = [];
        \http_response_code(200);

        \file_put_contents(
            $this->workDir . '/page.php',
            <<<'PHP'
                <?php
                use Polidog\UsePhp\Runtime\Element;
                use function Polidog\UsePhp\Runtime\useState;

                return fn (): Closure => function (): Element {
                    [$value, $setValue] = useState('initial');

                    return new Element('div', [], [
                        new Element('p', [], ['value=' . $value]),
                        new Element('button', ['wire:click' => $setValue('next')], ['go']),
                    ]);
                };
                PHP,
        );
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->workDir);
        $_POST = [];
        unset(
            $_SERVER['REQUEST_METHOD'],
            $_SERVER['REQUEST_URI'],
            $_SERVER['HTTP_X_USEPHP_PARTIAL'],
        );
    }

    #[RunInSeparateProcess]
    public function testStateActionWithoutTokenIsForbiddenAndLeavesStateUntouched(): void
    {
        $this->postStateAction(null, partial: true);

        $output = $this->dispatch();

        self::assertSame(403, \http_response_code());
        self::assertSame('initial', $this->currentState());
        self::assertStringNotContainsString('value=forged', $output);
    }

    #[RunInSeparateProcess]
    public function testStateActionWithInvalidTokenIsForbiddenAndLeavesStateUntouched(): void
    {
        // A session token exists, but the POST carries a different one.
        CsrfToken::getToken();
        $this->postStateAction('not-the-session-token', partial: true);

        $output = $this->dispatch();

        self::assertSame(403, \http_response_code());
        self::assertSame('initial', $this->currentState());
        self::assertStringNotContainsString('value=forged', $output);
    }

    #[RunInSeparateProcess]
    public function testStateActionWithoutTokenDoesNotRedirectOnFullPost(): void
    {
        $this->postStateAction(null, partial: false);

        $output = $this->dispatch();

        self::assertSame(403, \http_response_code());
        self::assertSame('initial', $this->currentState());
        // No PRG redirect: the render was not abandoned via StopRequest, so
        // the (unchanged) page is what the 403 carries.
        self::assertStringContainsString('value=initial', $output);
    }

    #[RunInSeparateProcess]
    public function testPartialStateActionWithValidTokenUpdatesState(): void
    {
        $token = CsrfToken::getToken();
        $this->postStateAction($token, partial: true);

        $output = $this->dispatch();

        self::assertSame(200, \http_response_code());
        self::assertSame('forged', $this->currentState());
        self::assertStringContainsString('value=forged', $output);
        // The partial response re-renders the setState form, which must
        // carry the token again so the next client-side update succeeds.
        self::assertStringContainsString($this->csrfField($token), $output);
    }

    #[RunInSeparateProcess]
    public function testFullPostStateActionWithValidTokenUpdatesStateAndRedirects(): void
    {
        $this->postStateAction(CsrfToken::getToken(), partial: false);

        $output = $this->dispatch();

        // PRG: the redirect is the response, nothing of the page is emitted.
        self::assertSame('', $output);
        self::assertSame('forged', $this->currentState());
    }

    #[RunInSeparateProcess]
    public function testRenderedSetStateFormCarriesSessionToken(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $output = $this->dispatch();

        self::assertStringContainsString('name="_usephp_action"', $output);
        self::assertStringContainsString($this->csrfField(CsrfToken::getToken()), $output);
    }

    #[RunInSeparateProcess]
    public function testPageWithoutStateActionDoesNotStartSession(): void
    {
        \file_put_contents(
            $this->workDir . '/page.php',
            <<<'PHP'
                <?php
                use Polidog\UsePhp\Runtime\Element;

                return fn (): Closure => fn (): Element => new Element('p', [], ['static']);
                PHP,
        );
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $output = $this->dispatch();

        self::assertStringContainsString('static', $output);
        self::assertStringNotContainsString('_usephp_csrf', $output);
        // Fetching the token would start a session (Set-Cookie +
        // no-store), defeating HTTP caching of plain pages.
        self::assertSame(\PHP_SESSION_NONE, \session_status());
    }

    private function postStateAction(?string $csrf, bool $partial): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/';
        if ($partial) {
            $_SERVER['HTTP_X_USEPHP_PARTIAL'] = '1';
        }
        $_POST = [
            '_usephp_component' => self::COMPONENT_ID,
            '_usephp_action' => self::SET_STATE,
        ];
        if (null !== $csrf) {
            $_POST['_usephp_csrf'] = $csrf;
        }
    }

    private function currentState(): mixed
    {
        return ComponentState::getInstance(self::COMPONENT_ID)->getState(0, 'initial');
    }

    private function csrfField(string $token): string
    {
        return \sprintf('name="_usephp_csrf" value="%s"', \htmlspecialchars($token, \ENT_QUOTES, 'UTF-8'));
    }

    private function dispatch(): string
    {
        $app = AppRouter::create($this->workDir);
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
        if (!\is_dir($path)) {
            return;
        }
        foreach (\scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $child = $path . '/' . $entry;
            \is_dir($child) ? $this->rmrf($child) : \unlink($child);
        }
        \rmdir($path);
    }
}
