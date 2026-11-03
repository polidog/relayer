<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Psx;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Psx\PsxComponentRegistrar;
use Polidog\Rosanna\Defer\DeferRequest;
use Polidog\Rosanna\Defer\RosannaDefer;
use Polidog\Rosanna\Runtime\PsxArgumentResolver;
use Polidog\Rosanna\Runtime\RenderContext;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;

final class DeferredComponentReportingTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/relayer-defer-report-' . \bin2hex(\random_bytes(6));
        \mkdir($this->workDir . '/Components', 0o777, true);
    }

    protected function tearDown(): void
    {
        \exec('rm -rf ' . \escapeshellarg($this->workDir));
    }

    public function testAFailingDeferredComponentReachesTheAppsLogger(): void
    {
        $this->writeComponent('Broken', <<<'PSX'
            return deferred(
                function (array $props) { throw new \RuntimeException('deferred-secret'); },
                new Defer(name: 'broken'),
            );
            PSX);
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [\is_string($level) ? $level : \get_debug_type($level), (string) $message, $context];
            }
        };
        $app = new RosannaDefer();
        PsxComponentRegistrar::configure($app, $this->workDir . '/Components', $this->workDir . '/cache', true, self::containerWith($logger));

        $response = $app->handleDeferred(new DeferRequest('GET', '/_defer/broken'));

        self::assertNotNull($response);
        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('deferred-secret', $response->body);
        self::assertCount(1, $logger->records);
        [$level, $message, $context] = $logger->records[0];
        self::assertSame('error', $level);
        self::assertStringContainsString("'broken'", $message);
        self::assertStringContainsString('deferred-secret', $message);
        self::assertInstanceOf(RuntimeException::class, $context['exception'] ?? null);
    }

    public function testAnAppWithoutAContainerCanStillPassItsOwnResolver(): void
    {
        $this->writeComponent('Greeting', 'return fn (array $props, ?string $who) => <p>hi {$who}</p>;');
        $resolver = new class implements PsxArgumentResolver {
            /** @var list<array<mixed>> */
            public array $seen = [];

            public function resolve(string $fqcn, array $metadata, array $props): array
            {
                $this->seen[] = $metadata;

                return ['custom'];
            }
        };
        $app = new RosannaDefer();
        PsxComponentRegistrar::configure($app, $this->workDir . '/Components', $this->workDir . '/cache', true, argumentResolver: $resolver);

        $html = RenderContext::run(
            $app->scope(),
            static fn (): string => $app->renderElement(RenderContext::scope()->psx->render('App\Components\Greeting')),
        );

        self::assertSame('<p>hi custom</p>', $html);
        // The manifest's parameters, as Rosanna's own loader would pass them.
        $second = $resolver->seen[0][1] ?? null;
        self::assertIsArray($second);
        self::assertSame('who', $second['name'] ?? null);
    }

    private function writeComponent(string $name, string $body): void
    {
        \file_put_contents($this->workDir . "/Components/{$name}.psx", "<?php\nnamespace App\\Components;\nuse Polidog\\Rosanna\\Defer\\Defer;\nuse function Polidog\\Rosanna\\Defer\\deferred;\n{$body}\n");
    }

    private static function containerWith(LoggerInterface $logger): ContainerInterface
    {
        return new class($logger) implements ContainerInterface {
            public function __construct(private readonly LoggerInterface $logger) {}

            public function has(string $id): bool
            {
                return LoggerInterface::class === $id;
            }

            public function get(string $id): object
            {
                if (LoggerInterface::class !== $id) {
                    throw new class("not found: {$id}") extends RuntimeException implements NotFoundExceptionInterface {};
                }

                return $this->logger;
            }
        };
    }
}
