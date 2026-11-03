<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Di;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Di\RouteFileClasses;

final class RouteFileClassesTest extends TestCase
{
    private string $appDir;

    protected function setUp(): void
    {
        $this->appDir = \sys_get_temp_dir() . '/route-file-classes-' . \bin2hex(\random_bytes(6));
        \mkdir($this->appDir . '/(shop)/cart', 0o777, true);
        \mkdir($this->appDir . '/about', 0o777, true);
        \mkdir($this->appDir . '/api', 0o777, true);

        \file_put_contents($this->appDir . '/layout.php', "<?php\nnamespace App\\Pages;\nfinal class RootLayout {}\n");
        \file_put_contents($this->appDir . '/error.psx', "<?php\nnamespace App\\Pages;\nfinal class ErrorPage {}\n");
        \file_put_contents($this->appDir . '/(shop)/cart/page.psx', <<<'PSX'
            <?php
            declare(strict_types=1);
            namespace App\Pages\Shop;
            use Polidog\Rosanna\Runtime\Element;
            final class CartPage
            {
                public function render(): Element
                {
                    return <div class="cart"><p>Don't forget</p></div>;
                }
            }
            PSX);
        // A function-style page declares no class.
        \file_put_contents($this->appDir . '/about/page.psx', "<?php\nreturn fn () => <main class=\"about\">About</main>;\n");
        // Not a page, layout or error file.
        \file_put_contents($this->appDir . '/api/route.php', "<?php\nnamespace App\\Pages;\nfinal class ApiRoute {}\n");
        \file_put_contents($this->appDir . '/about/Helper.php', "<?php\nnamespace App\\Pages;\nfinal class Helper {}\n");
    }

    protected function tearDown(): void
    {
        foreach (['/layout.php', '/error.psx', '/(shop)/cart/page.psx', '/about/page.psx', '/api/route.php', '/about/Helper.php'] as $file) {
            @\unlink($this->appDir . $file);
        }
        foreach (['/(shop)/cart', '/(shop)', '/about', '/api', ''] as $dir) {
            @\rmdir($this->appDir . $dir);
        }
    }

    public function testFindsTheClassOfEveryPageLayoutAndErrorFile(): void
    {
        $classes = RouteFileClasses::inAppDirectory($this->appDir);
        \sort($classes);

        self::assertSame(['App\Pages\ErrorPage', 'App\Pages\RootLayout', 'App\Pages\Shop\CartPage'], $classes);
    }

    public function testAMissingDirectoryHasNoClasses(): void
    {
        self::assertSame([], RouteFileClasses::inAppDirectory($this->appDir . '/missing'));
    }
}
