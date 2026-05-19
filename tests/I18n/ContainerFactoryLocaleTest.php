<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\I18n;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\I18n\LocaleResolver;
use Polidog\Relayer\Relayer;
use Polidog\Relayer\Router\AppRouter;
use Psr\Container\ContainerInterface;
use ReflectionProperty;

/**
 * End-to-end wiring: the LocaleResolver the container hands back must
 * honor the "path-prefix routing only with 2+ locales" gate, so a
 * no-i18n / single-locale app is byte-identical to pre-i18n behavior.
 */
final class ContainerFactoryLocaleTest extends TestCase
{
    private const ENV_KEYS = ['APP_ENV', 'APP_LOCALE', 'APP_LOCALES', 'LOCALE_PATH_PREFIX', 'LOCALE_COOKIE'];

    private string $projectRoot;

    protected function setUp(): void
    {
        $this->clearEnv();
        $this->projectRoot = \sys_get_temp_dir() . '/cf-locale-' . \uniqid();
        \mkdir($this->projectRoot . '/src/Pages', 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->projectRoot);
        $this->clearEnv();
    }

    public function testSingleLocaleAppDoesNotTreatFirstSegmentAsLocale(): void
    {
        $resolved = $this->resolver("APP_ENV=dev\n")
            ->resolve(new Request(method: 'GET', path: '/en/dashboard'))
        ;

        self::assertSame('en', $resolved->locale);
        self::assertSame('/en/dashboard', $resolved->path, 'no rewrite for a single-locale app');
        self::assertSame('default', $resolved->source);
    }

    public function testMultiLocaleAppStripsThePrefix(): void
    {
        $resolved = $this->resolver("APP_ENV=dev\nAPP_LOCALES=en,ja\n")
            ->resolve(new Request(method: 'GET', path: '/ja/dashboard'))
        ;

        self::assertSame('ja', $resolved->locale);
        self::assertSame('/dashboard', $resolved->path);
        self::assertSame('path', $resolved->source);
    }

    public function testPathPrefixOptOutKeepsCookieResolution(): void
    {
        $resolver = $this->resolver("APP_ENV=dev\nAPP_LOCALES=en,ja\nLOCALE_PATH_PREFIX=false\n");

        $prefix = $resolver->resolve(new Request(method: 'GET', path: '/ja/dashboard'));
        self::assertSame('/ja/dashboard', $prefix->path, 'opted out of prefix routing');
        self::assertSame('en', $prefix->locale);

        $cookie = $resolver->resolve(
            new Request(method: 'GET', path: '/dashboard', cookies: ['locale' => 'ja']),
        );
        self::assertSame('ja', $cookie->locale);
        self::assertSame('cookie', $cookie->source);
    }

    private function clearEnv(): void
    {
        foreach (self::ENV_KEYS as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    private function resolver(string $env): LocaleResolver
    {
        \file_put_contents($this->projectRoot . '/.env', $env);

        $router = Relayer::boot($this->projectRoot);

        $property = new ReflectionProperty(AppRouter::class, 'container');
        $container = $property->getValue($router);
        self::assertInstanceOf(ContainerInterface::class, $container);

        $resolver = $container->get(LocaleResolver::class);
        self::assertInstanceOf(LocaleResolver::class, $resolver);

        return $resolver;
    }

    private function rmrf(string $path): void
    {
        if (!\file_exists($path)) {
            return;
        }
        if (\is_file($path) || \is_link($path)) {
            @\unlink($path);

            return;
        }
        $entries = \scandir($path);
        if (false === $entries) {
            return;
        }
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $this->rmrf($path . '/' . $entry);
        }
        @\rmdir($path);
    }
}
