<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Component;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Router\Form\CsrfToken;
use Polidog\Relayer\Router\Form\FormAction;
use Polidog\Relayer\Router\HttpException;
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

        $this->post($page, ['_rosanna_action' => $token, 'title' => 'hello']);

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

        $this->post($page, ['_rosanna_action' => $token, 'id' => '999', 'formData' => 'x']);

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

        $this->assertForbidden($page, ['_rosanna_action' => $forged]);

        self::assertSame([], $page->calls);
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
        $this->assertForbidden($page, ['_rosanna_action' => $unsigned]);

        $resigned = FormActionTokens::withPayload($page->saveToken(), [
            'class' => FormActionPage::class,
            'method' => 'internalOnly',
            'args' => [],
        ]);
        $this->assertForbidden($page, ['_rosanna_action' => $resigned]);

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

        $this->assertForbidden($page, ['_rosanna_action' => $forged]);

        self::assertSame([], $page->calls);
    }

    #[RunInSeparateProcess]
    public function testInheritedFrameworkMethodIsNeverDispatched(): void
    {
        // Even a correctly signed token naming a PageComponent internal is
        // refused: only methods declared on the concrete page are actions.
        $page = new FormActionPage();
        $token = FormAction::create(FormActionPage::class, 'setParams', [['injected' => 'x']]);

        $this->post($page, ['_rosanna_action' => $token]);

        self::assertSame([], $page->calls);
    }

    #[RunInSeparateProcess]
    public function testTokenFromAnotherSessionIsRejected(): void
    {
        $page = new FormActionPage();
        $token = $page->saveToken(['id' => 1]);

        // Rotate the signing key, as a different session would have.
        unset($_SESSION['rosanna:form-action-key']);
        $page->saveToken();

        $this->assertForbidden($page, ['_rosanna_action' => $token]);

        self::assertSame([], $page->calls);
    }

    #[RunInSeparateProcess]
    public function testInvalidCsrfIsRefusedWith403(): void
    {
        $page = new FormActionPage();
        $token = $page->saveToken(['id' => 1]);
        CsrfToken::getToken();

        $page->setRequest(new Request('POST', '/', post: ['_rosanna_action' => $token, '_rosanna_csrf' => 'bogus']));

        try {
            $page->dispatchActionFromRequest();
            self::fail('Expected the action to be refused with a 403.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->status);
        }

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
        $post['_rosanna_csrf'] = CsrfToken::getToken();
        $page->setRequest(new Request('POST', '/', post: $post));
        $page->dispatchActionFromRequest();
    }

    /**
     * A refused action unwinds as `HttpException(403)`, which the router
     * answers with the error page, like `$ctx->abort(403)`.
     *
     * @param array<string, mixed> $post
     */
    private function assertForbidden(FormActionPage $page, array $post): void
    {
        try {
            $this->post($page, $post);
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->status);

            return;
        }

        self::fail('Expected the action to be refused with a 403.');
    }
}
