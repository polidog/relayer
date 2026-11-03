<?php

declare(strict_types=1);

namespace Polidog\Relayer\Di;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Finds the classes the router turns into pages, layouts and error pages:
 * the class declared in each `page`, `layout` and `error` file (`.php` or
 * `.psx`) under the app directory.
 *
 * The router accepts any `Renderable` as a page, so the base class alone
 * cannot tell a page from any other component; the file it lives in can.
 * The class name is read from the file's tokens, so a `.psx` source works
 * without being compiled or loaded.
 *
 * @internal
 */
final class RouteFileClasses
{
    private const array FILES = [
        'page.php', 'page.psx',
        'layout.php', 'layout.psx',
        'error.php', 'error.psx',
    ];

    /**
     * @return list<string> class names, as declared (no leading backslash)
     */
    public static function inAppDirectory(string $appDirectory): array
    {
        if (!\is_dir($appDirectory)) {
            return [];
        }

        try {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($appDirectory, FilesystemIterator::SKIP_DOTS),
            );
        } catch (UnexpectedValueException) {
            return [];
        }

        $classes = [];

        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()
                || !\in_array($file->getFilename(), self::FILES, true)
            ) {
                continue;
            }

            $class = self::declaredIn($file->getPathname());
            if (null !== $class) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * The first class declared in a file, with its namespace, or null when
     * it declares none (a function-style page returns a closure instead).
     */
    public static function declaredIn(string $filePath): ?string
    {
        $content = @\file_get_contents($filePath);

        if (false === $content) {
            return null;
        }

        $tokens = \token_get_all($content);
        $tokenCount = \count($tokens);
        $namespace = null;
        $className = null;

        for ($i = 0; $i < $tokenCount; ++$i) {
            $token = $tokens[$i];

            if (!\is_array($token)) {
                continue;
            }

            if (\T_NAMESPACE === $token[0]) {
                $namespaceParts = [];
                ++$i;

                while ($i < $tokenCount) {
                    $nextToken = $tokens[$i];

                    if (';' === $nextToken || '{' === $nextToken) {
                        break;
                    }

                    if (\is_array($nextToken)) {
                        if (\T_NAME_QUALIFIED === $nextToken[0] || \T_STRING === $nextToken[0]) {
                            $namespaceParts[] = $nextToken[1];
                        }
                    }

                    ++$i;
                }

                $namespace = \implode('', $namespaceParts);
            }

            if (\T_CLASS === $token[0]) {
                ++$i;

                while ($i < $tokenCount) {
                    $nextToken = $tokens[$i];

                    if (\is_array($nextToken) && \T_STRING === $nextToken[0]) {
                        $className = $nextToken[1];

                        break;
                    }

                    if (\is_array($nextToken) && \T_WHITESPACE === $nextToken[0]) {
                        ++$i;

                        continue;
                    }

                    break;
                }

                if (null !== $className) {
                    break;
                }
            }
        }

        if (null === $className) {
            return null;
        }

        if (null !== $namespace && '' !== $namespace) {
            return $namespace . '\\' . $className;
        }

        return $className;
    }
}
