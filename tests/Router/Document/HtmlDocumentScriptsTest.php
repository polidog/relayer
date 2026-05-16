<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Document;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Router\Document\HtmlDocument;
use Polidog\Relayer\Router\Document\Script;

final class HtmlDocumentScriptsTest extends TestCase
{
    public function testAddScriptIsFluent(): void
    {
        $document = new HtmlDocument();

        self::assertSame($document, $document->addScript(new Script('/a.js')));
    }

    public function testNoScriptsLeavesBodyWithOnlyTheUsephpBundle(): void
    {
        $html = (new HtmlDocument())->render('<p>hi</p>');

        self::assertStringContainsString('<script src="/usephp.js"></script>', $html);
        self::assertSame(1, \substr_count($html, '<script'));
    }

    public function testScriptsAreEmittedAfterTheUsephpBundleInAddOrder(): void
    {
        $html = (new HtmlDocument())
            ->addScript(new Script('/first.js'))
            ->addScript(new Script('/second.js', defer: true))
            ->render('<p>hi</p>')
        ;

        $usephp = \strpos($html, '<script src="/usephp.js">');
        $first = \strpos($html, '<script src="/first.js">');
        $second = \strpos($html, '<script src="/second.js" defer>');

        self::assertNotFalse($usephp);
        self::assertNotFalse($first);
        self::assertNotFalse($second);
        self::assertLessThan($first, $usephp);
        self::assertLessThan($second, $first);
    }

    public function testErrorPageHasNoAppScripts(): void
    {
        $html = (new HtmlDocument())
            ->addScript(new Script('/app.js'))
            ->renderError(500, 'Boom')
        ;

        self::assertStringNotContainsString('/app.js', $html);
    }
}
