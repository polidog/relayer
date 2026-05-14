<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Profiler;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Profiler\Event;
use Polidog\Relayer\Profiler\Profile;
use Polidog\Relayer\Profiler\ProfilerStorage;
use Polidog\Relayer\Profiler\ProfilerWebView;

final class ProfilerWebViewTest extends TestCase
{
    public function testIndexListsRecentProfiles(): void
    {
        $storage = new InMemoryProfilerStorage();
        $storage->saved[] = $this->makeProfile('aaa', '/users', 'GET', 200);
        $storage->saved[] = $this->makeProfile('bbb', '/login', 'POST', 302);

        $html = (new ProfilerWebView($storage))->renderIndex();

        self::assertStringContainsString('Relayer Profiler', $html);
        self::assertStringContainsString('/users', $html);
        self::assertStringContainsString('/login', $html);
        // Tokens become detail-page links.
        self::assertStringContainsString('href="/_profiler/aaa"', $html);
        self::assertStringContainsString('href="/_profiler/bbb"', $html);
        // Status classes feed CSS.
        self::assertStringContainsString('status-2xx', $html);
        self::assertStringContainsString('status-3xx', $html);
    }

    public function testIndexShowsEmptyStateWhenNoProfiles(): void
    {
        $html = (new ProfilerWebView(new InMemoryProfilerStorage()))->renderIndex();

        self::assertStringContainsString('No profiles recorded yet', $html);
    }

    public function testDetailRendersEventsAndPayload(): void
    {
        $storage = new InMemoryProfilerStorage();
        $profile = $this->makeProfile('tok1', '/users', 'GET', 200);
        $profile->addEvent(new Event('route', 'match', ['pattern' => '/users'], \microtime(true)));
        $profile->addEvent(new Event('page', 'render', ['componentId' => 'page:/users'], \microtime(true), 12.34));
        $storage->saved[$profile->token] = $profile;

        $html = (new ProfilerWebView($storage))->renderDetail('tok1');

        self::assertStringContainsString('GET /users', $html);
        self::assertStringContainsString('tok1', $html);
        // Both event collectors appear as tags.
        self::assertStringContainsString('>route<', $html);
        self::assertStringContainsString('>page<', $html);
        // Payload is JSON-encoded and HTML-escaped.
        self::assertStringContainsString('&quot;pattern&quot;: &quot;/users&quot;', $html);
        self::assertStringContainsString('12.34 ms', $html);
    }

    public function testDetailHandlesMissingToken(): void
    {
        $html = (new ProfilerWebView(new InMemoryProfilerStorage()))->renderDetail('nope');

        self::assertStringContainsString('Profile not found', $html);
        self::assertStringContainsString('nope', $html);
    }

    public function testDetailEscapesUntrustedToken(): void
    {
        // The router's regex guard rejects this before render, but if a future
        // caller bypasses that, the view itself must not echo raw HTML.
        $html = (new ProfilerWebView(new InMemoryProfilerStorage()))->renderDetail('<script>x</script>');

        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    private function makeProfile(string $token, string $url, string $method, int $status): Profile
    {
        $profile = new Profile($token, $url, $method, \microtime(true));
        $profile->end($status);

        return $profile;
    }
}
