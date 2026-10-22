<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Component;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Router\Form\CsrfToken;
use Polidog\Relayer\Router\Form\FormAction;
use Polidog\Relayer\Tests\Fixtures\FormActionPage;
use Polidog\Relayer\Tests\Fixtures\FormActionTokens;

/**
 * Class-style (PageComponent) form-action dispatch. Tokens are signed with a
 * session-held key and CsrfToken starts the session, so each test that
 * touches either runs in its own process.
 */
final class PageComponentActionDispatchTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testSignedTokenDispatchesWithBoundArgs(): void
    {
        $page = new FormActionPage();
        $token = $page->saveToken(['id' => 123]);

        $this->post($page, ['_usephp_action' => $token, 'title' => 'hello']);

        self::assertSame(
            [['method' => 'save', 'formData' => ['title' => 'hello'], 'id' => 123]],
            $page->calls,
        );
    }

    #[RunInSeparateProcess]
    public function testPostedFieldCannotOverrideBoundArgument(): void
    {
        $page = new FormActionPage();
        $token = $page->saveToken(['id' => 123]);

        $this->post($page, ['_usephp_action' => $token, 'id' => '999', 'formData' => 'x']);

        self::assertCount(1, $page->calls);
        self::assertSame(123, $page->calls[0]['id']);
        self::assertSame(['id' => '999', 'formData' => 'x'], $page->calls[0]['formData']);
    }

    #[RunInSeparateProcess]
    public function testTamperedArgsAreRejected(): void
    {
        $page = new FormActionPage();
        $token = $page->saveToken(['id' => 123]);

        $forged = FormActionTokens::withPayload($token, [
            'class' => FormActionPage::class,
            'method' => 'save',
            'args' => ['id' => 999],
        ]);

        $this->post($page, ['_usephp_action' => $forged]);

        self::assertSame([], $page->calls);
        self::assertSame(403, \http_response_code());
    }

    #[RunInSeparateProcess]
    public function testForgedTokenForProtectedMethodHasNoEffect(): void
    {
        $page = new FormActionPage();
        // Mint a legit token so the session has a signing key, then forge.
        $page->saveToken();

        $unsigned = FormActionTokens::unsigned([
            'class' => FormActionPage::class,
            'method' => 'internalOnly',
            'args' => [],
        ]);
        $this->post($page, ['_usephp_action' => $unsigned]);

        $resigned = FormActionTokens::withPayload($page->saveToken(), [
            'class' => FormActionPage::class,
            'method' => 'internalOnly',
            'args' => [],
        ]);
        $this->post($page, ['_usephp_action' => $resigned]);

        self::assertSame([], $page->calls);
    }

    #[RunInSeparateProcess]
    public function testForgedTokenForUnregisteredPublicMethodHasNoEffect(): void
    {
        $page = new FormActionPage();
        $page->saveToken();

        $forged = FormActionTokens::unsigned([
            'class' => FormActionPage::class,
            'method' => 'unregisteredPublic',
            'args' => [],
        ]);

        $this->post($page, ['_usephp_action' => $forged]);

        self::assertSame([], $page->calls);
    }

    #[RunInSeparateProcess]
    public function testInheritedFrameworkMethodIsNeverDispatched(): void
    {
        // Even a correctly signed token naming a PageComponent internal is
        // refused: only methods declared on the concrete page are actions.
        $page = new FormActionPage();
        $token = FormAction::create(FormActionPage::class, 'setParams', [['injected' => 'x']]);

        $this->post($page, ['_usephp_action' => $token]);

        self::assertSame([], $page->calls);
    }

    #[RunInSeparateProcess]
    public function testTokenFromAnotherSessionIsRejected(): void
    {
        $page = new FormActionPage();
        $token = $page->saveToken(['id' => 1]);

        // Rotate the signing key, as a different session would have.
        unset($_SESSION['usephp:form-action-key']);
        $page->saveToken();

        $this->post($page, ['_usephp_action' => $token]);

        self::assertSame([], $page->calls);
    }

    public function testActionRejectsInheritedMethod(): void
    {
        $page = new FormActionPage();

        $this->expectException(InvalidArgumentException::class);
        $page->tokenForInherited();
    }

    /**
     * @param array<string, mixed> $post
     */
    private function post(FormActionPage $page, array $post): void
    {
        $post['_usephp_csrf'] = CsrfToken::getToken();
        $page->setRequest(new Request('POST', '/', post: $post));
        $page->dispatchActionFromRequest();
    }
}
