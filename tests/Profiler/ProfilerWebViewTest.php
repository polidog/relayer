<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Profiler;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Profiler\Event;
use Polidog\Relayer\Profiler\Profile;
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

    public function testIndexFoldsChildrenUnderParentRow(): void
    {
        $storage = new InMemoryProfilerStorage();
        $parent = $this->makeProfile('par1', '/page', 'GET', 200);
        $childA = $this->makeProfile('cha1', '/page', 'POST', 200, parentToken: 'par1');
        $childB = $this->makeProfile('chb2', '/page', 'POST', 200, parentToken: 'par1');
        // Insert children before parent to verify lookup is order-independent.
        $storage->saved[$childA->token] = $childA;
        $storage->saved[$childB->token] = $childB;
        $storage->saved[$parent->token] = $parent;

        $html = (new ProfilerWebView($storage))->renderIndex();

        // Children rows are hidden — only the parent row's URL link appears.
        self::assertStringContainsString('href="/_profiler/par1"', $html);
        self::assertStringNotContainsString('href="/_profiler/cha1"', $html);
        self::assertStringNotContainsString('href="/_profiler/chb2"', $html);
        // Badge surfaces the child count alongside the parent URL.
        self::assertStringContainsString('+2 defer', $html);
    }

    public function testIndexSurfacesOrphanChildWhenParentMissingFromBatch(): void
    {
        // When the parent profile is gone (evicted, excluded, etc.) the child
        // must still be reachable from the index — otherwise the defer fetch
        // would be invisible despite the storage holding it.
        $storage = new InMemoryProfilerStorage();
        $child = $this->makeProfile('orphan1', '/page', 'POST', 200, parentToken: 'gone-parent-123');
        $storage->saved[$child->token] = $child;

        $html = (new ProfilerWebView($storage))->renderIndex();

        self::assertStringContainsString('href="/_profiler/orphan1"', $html);
    }

    public function testDetailRendersParentLinkAndChildrenList(): void
    {
        $storage = new InMemoryProfilerStorage();
        $parent = $this->makeProfile('par1', '/page', 'GET', 200);
        $parent->addEvent(new Event('route', 'match', ['pattern' => '/page'], $parent->startedAt));
        $child = $this->makeProfile('cha1', '/page', 'POST', 200, parentToken: 'par1');
        $storage->saved[$parent->token] = $parent;
        $storage->saved[$child->token] = $child;

        // Parent's detail page lists the child under "Sub-requests".
        $parentHtml = (new ProfilerWebView($storage))->renderDetail('par1');
        self::assertStringContainsString('Sub-requests', $parentHtml);
        self::assertStringContainsString('href="/_profiler/cha1"', $parentHtml);

        // Child's detail page links back to the parent and labels it.
        $childHtml = (new ProfilerWebView($storage))->renderDetail('cha1');
        self::assertStringContainsString('Parent', $childHtml);
        self::assertStringContainsString('href="/_profiler/par1"', $childHtml);
    }

    public function testDetailMarksParentAsUnavailableWhenMissing(): void
    {
        $storage = new InMemoryProfilerStorage();
        $orphan = $this->makeProfile('orphan2', '/page', 'POST', 200, parentToken: 'gone-parent-456');
        $storage->saved[$orphan->token] = $orphan;

        $html = (new ProfilerWebView($storage))->renderDetail('orphan2');

        self::assertStringContainsString('Parent', $html);
        self::assertStringContainsString('unavailable', $html);
        // The dangling token is still surfaced (escaped) so the developer
        // can correlate manually if logs still hold it.
        self::assertStringContainsString('gone-parent-456', $html);
    }

    private function makeProfile(string $token, string $url, string $method, int $status, ?string $parentToken = null): Profile
    {
        $profile = new Profile($token, $url, $method, \microtime(true), parentToken: $parentToken);
        $profile->end($status);

        return $profile;
    }
}
