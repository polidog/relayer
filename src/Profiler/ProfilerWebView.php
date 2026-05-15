<?php

declare(strict_types=1);

namespace Polidog\Relayer\Profiler;

use Polidog\Relayer\Router\TraceableAppRouter;

/**
 * Renders the dev-only profiler UI: a list of recent profiles and a per-
 * profile detail page. Pure HTML — no JS, no external CSS — so the view
 * works offline in any environment that hits `/_profiler` in development.
 *
 * Wired in by {@see TraceableAppRouter} which
 * intercepts requests under `/_profiler` before normal dispatch (so the
 * view does not create a profile of itself).
 */
final class ProfilerWebView
{
    public function __construct(private readonly ProfilerStorage $storage) {}

    public function renderIndex(int $limit = 50): string
    {
        // Look further back than the visible limit so we can fold child
        // profiles into their parents without dropping parents off the
        // visible list. Without this, a page that emits multiple defer
        // fetches would push its own parent row out of the recent batch.
        $lookupLimit = \max($limit * 4, 50);
        $profiles = $this->storage->recent($lookupLimit);

        $byToken = [];
        foreach ($profiles as $profile) {
            $byToken[$profile->token] = $profile;
        }

        // Group children under whichever in-batch parent they reference.
        // Orphans (parent not in batch — e.g. evicted or excluded) fall
        // back to top-level rendering so they remain discoverable.
        $childrenByParent = [];
        $topLevel = [];
        foreach ($profiles as $profile) {
            $parent = $profile->parentToken;
            if (null !== $parent && isset($byToken[$parent])) {
                $childrenByParent[$parent][] = $profile;

                continue;
            }
            $topLevel[] = $profile;
        }

        $topLevel = \array_slice($topLevel, 0, $limit);

        $rows = '';
        foreach ($topLevel as $profile) {
            $children = $childrenByParent[$profile->token] ?? [];
            $rows .= $this->renderIndexRow($profile, \count($children));
        }

        if ('' === $rows) {
            $rows = '<tr><td colspan="5" class="empty">No profiles recorded yet. Visit a page first.</td></tr>';
        }

        $body = <<<HTML
            <h1>Relayer Profiler</h1>
            <p class="meta">Showing most recent {$limit} profiles. Newest first. Defer sub-requests are folded into their parent row.</p>
            <table>
                <thead>
                    <tr><th>Time</th><th>Method</th><th>URL</th><th>Status</th><th>Duration</th></tr>
                </thead>
                <tbody>{$rows}</tbody>
            </table>
            HTML;

        return $this->layout('Relayer Profiler', $body);
    }

    public function renderDetail(string $token): string
    {
        $profile = $this->storage->load($token);
        if (null === $profile) {
            return $this->layout('Profile not found', '<h1>Profile not found</h1>'
                . '<p class="meta">Token <code>' . self::h($token) . '</code> is unknown — it may have been overwritten.</p>'
                . '<p><a href="/_profiler">← Back to index</a></p>');
        }

        $duration = $profile->durationMs();
        $durationText = null === $duration ? '—' : \sprintf('%.2f ms', $duration);
        $startedAt = \date('Y-m-d H:i:s', (int) $profile->startedAt);

        $eventRows = '';
        foreach ($profile->getEvents() as $event) {
            $ts = $event->ts - $profile->startedAt;
            $eventDuration = null === $event->durationMs ? '—' : \sprintf('%.2f ms', $event->durationMs);
            $payload = self::h(\json_encode(
                $event->payload,
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR,
            ) ?: '{}');
            $eventRows .= \sprintf(
                '<tr><td class="num">%s</td><td><span class="tag">%s</span> %s</td><td class="num">%s</td><td><pre>%s</pre></td></tr>',
                \sprintf('+%.2f ms', $ts * 1000),
                self::h($event->collector),
                self::h($event->label),
                $eventDuration,
                $payload,
            );
        }

        if ('' === $eventRows) {
            $eventRows = '<tr><td colspan="4" class="empty">No events recorded for this request.</td></tr>';
        }

        $statusBadge = $this->statusBadge($profile->getStatusCode());
        $token = self::h($profile->token);
        $url = self::h($profile->url);
        $method = self::h($profile->method);
        $parentRow = $this->renderParentRow($profile->parentToken);
        $childrenSection = $this->renderChildrenSection($profile);

        $body = <<<HTML
            <p><a href="/_profiler">← Back to index</a></p>
            <h1>{$method} {$url}</h1>
            <dl class="summary">
                <dt>Token</dt><dd><code>{$token}</code></dd>
                {$parentRow}
                <dt>Status</dt><dd>{$statusBadge}</dd>
                <dt>Started</dt><dd>{$startedAt}</dd>
                <dt>Duration</dt><dd>{$durationText}</dd>
            </dl>
            <h2>Events</h2>
            <table>
                <thead>
                    <tr><th>t+</th><th>Collector</th><th>Duration</th><th>Payload</th></tr>
                </thead>
                <tbody>{$eventRows}</tbody>
            </table>
            {$childrenSection}
            HTML;

        return $this->layout("Profile {$token}", $body);
    }

    private function renderParentRow(?string $parentToken): string
    {
        if (null === $parentToken) {
            return '';
        }

        $parent = $this->storage->load($parentToken);
        $escapedToken = self::h($parentToken);
        if (null === $parent) {
            return "<dt>Parent</dt><dd><code>{$escapedToken}</code> <span class=\"meta\">(unavailable)</span></dd>";
        }

        $parentUrl = self::h($parent->url);

        return "<dt>Parent</dt><dd><a href=\"/_profiler/{$escapedToken}\">{$parentUrl}</a></dd>";
    }

    private function renderChildrenSection(Profile $profile): string
    {
        $children = $this->storage->childrenOf($profile->token);
        if ([] === $children) {
            return '';
        }

        $rows = '';
        foreach ($children as $child) {
            $offsetMs = ($child->startedAt - $profile->startedAt) * 1000;
            $childDuration = $child->durationMs();
            $childDurationText = null === $childDuration ? '—' : \sprintf('%.2f ms', $childDuration);
            $childToken = self::h($child->token);
            $childUrl = self::h($child->url);
            $childMethod = self::h($child->method);
            $childStatus = $this->statusBadge($child->getStatusCode());
            $rows .= \sprintf(
                '<tr class="child"><td class="num">%s</td><td><span class="method">%s</span></td>'
                . '<td><a href="/_profiler/%s">%s</a></td><td>%s</td><td class="num">%s</td></tr>',
                \sprintf('+%.2f ms', $offsetMs),
                $childMethod,
                $childToken,
                $childUrl,
                $childStatus,
                $childDurationText,
            );
        }

        $count = \count($children);

        return <<<HTML
            <h2>Sub-requests <span class="meta">({$count})</span></h2>
            <table>
                <thead>
                    <tr><th>t+</th><th>Method</th><th>URL</th><th>Status</th><th>Duration</th></tr>
                </thead>
                <tbody>{$rows}</tbody>
            </table>
            HTML;
    }

    private function renderIndexRow(Profile $profile, int $childCount = 0): string
    {
        $duration = $profile->durationMs();
        $durationText = null === $duration ? '—' : \sprintf('%.1f ms', $duration);
        $startedAt = \date('H:i:s', (int) $profile->startedAt);
        $token = self::h($profile->token);
        $url = self::h($profile->url);
        $method = self::h($profile->method);
        $status = $this->statusBadge($profile->getStatusCode());
        $deferBadge = $childCount > 0
            ? \sprintf(' <span class="defer-badge" title="defer sub-requests">+%d defer</span>', $childCount)
            : '';

        return <<<HTML
            <tr>
                <td>{$startedAt}</td>
                <td><span class="method">{$method}</span></td>
                <td><a href="/_profiler/{$token}">{$url}</a>{$deferBadge}</td>
                <td>{$status}</td>
                <td class="num">{$durationText}</td>
            </tr>
            HTML;
    }

    private function statusBadge(?int $status): string
    {
        if (null === $status) {
            return '<span class="status">—</span>';
        }
        $klass = match (true) {
            $status >= 500 => 'status status-5xx',
            $status >= 400 => 'status status-4xx',
            $status >= 300 => 'status status-3xx',
            default => 'status status-2xx',
        };

        return \sprintf('<span class="%s">%d</span>', self::h($klass), $status);
    }

    private function layout(string $title, string $body): string
    {
        $title = self::h($title);

        return <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <title>{$title}</title>
                <style>
                    body { font: 14px/1.5 ui-sans-serif, system-ui, -apple-system, sans-serif; margin: 0; background: #fafafa; color: #222; }
                    main { max-width: 1100px; margin: 0 auto; padding: 1.5rem; }
                    h1 { margin: 0 0 0.5rem; font-size: 1.4rem; }
                    h2 { margin: 1.5rem 0 0.5rem; font-size: 1.05rem; color: #444; }
                    .meta { color: #666; margin: 0 0 1rem; }
                    a { color: #2563eb; text-decoration: none; }
                    a:hover { text-decoration: underline; }
                    code { font-family: ui-monospace, SFMono-Regular, monospace; font-size: 0.9em; background: #eee; padding: 1px 4px; border-radius: 3px; }
                    table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e5e5e5; border-radius: 6px; overflow: hidden; }
                    th, td { padding: 0.5rem 0.75rem; text-align: left; border-bottom: 1px solid #eee; vertical-align: top; }
                    th { background: #f3f4f6; font-weight: 600; font-size: 0.85em; color: #555; }
                    tr:last-child td { border-bottom: none; }
                    td.num { font-variant-numeric: tabular-nums; color: #555; white-space: nowrap; }
                    td.empty { color: #999; text-align: center; padding: 1rem; }
                    .method { font-weight: 600; font-size: 0.85em; color: #444; }
                    .tag { display: inline-block; padding: 1px 6px; background: #eef2ff; color: #3730a3; border-radius: 3px; font-size: 0.8em; font-weight: 600; margin-right: 4px; }
                    .defer-badge { display: inline-block; padding: 1px 6px; margin-left: 6px; background: #fff7ed; color: #9a3412; border-radius: 10px; font-size: 0.75em; font-weight: 600; }
                    tr.child td { background: #fbfaf6; }
                    .status { display: inline-block; padding: 1px 8px; border-radius: 10px; font-weight: 600; font-size: 0.85em; }
                    .status-2xx { background: #dcfce7; color: #166534; }
                    .status-3xx { background: #dbeafe; color: #1e40af; }
                    .status-4xx { background: #fef3c7; color: #92400e; }
                    .status-5xx { background: #fee2e2; color: #991b1b; }
                    dl.summary { display: grid; grid-template-columns: max-content 1fr; gap: 0.25rem 1rem; margin: 0 0 1rem; }
                    dl.summary dt { color: #666; font-size: 0.85em; }
                    dl.summary dd { margin: 0; }
                    pre { margin: 0; padding: 0.5rem; background: #f8f8f8; border-radius: 4px; font-size: 0.85em; white-space: pre-wrap; word-break: break-all; }
                </style>
            </head>
            <body><main>{$body}</main></body>
            </html>
            HTML;
    }

    private static function h(string $value): string
    {
        return \htmlspecialchars($value, \ENT_QUOTES, 'UTF-8');
    }
}
