<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Http;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\UploadedFile;
use RuntimeException;

final class UploadedFileTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (\is_file($path)) {
                \unlink($path);
            }
        }
        $this->paths = [];
    }

    public function testFromArrayReadsThePhpUploadShape(): void
    {
        $file = UploadedFile::fromArray([
            'name' => 'report.csv',
            'type' => 'text/csv',
            'size' => 7,
            'tmp_name' => '/tmp/phpABC',
            'error' => \UPLOAD_ERR_OK,
        ]);

        self::assertSame('report.csv', $file->clientName);
        self::assertSame('text/csv', $file->clientMimeType);
        self::assertSame(7, $file->size);
        self::assertSame('/tmp/phpABC', $file->tmpName);
        self::assertTrue($file->isValid());
    }

    public function testAnEmptyFieldIsNotValid(): void
    {
        $file = UploadedFile::fromArray([
            'name' => '',
            'type' => '',
            'size' => 0,
            'tmp_name' => '',
            'error' => \UPLOAD_ERR_NO_FILE,
        ]);

        self::assertFalse($file->isValid());
    }

    public function testContentsReadsTheTempFile(): void
    {
        $file = $this->uploadOf('hello');

        self::assertSame('hello', $file->contents());
    }

    public function testMoveToRelocatesTheFile(): void
    {
        $file = $this->uploadOf('payload');
        $target = $this->tempPath('target');

        $file->moveTo($target);

        self::assertFileDoesNotExist($file->tmpName);
        self::assertSame('payload', \file_get_contents($target));
    }

    public function testMoveToRejectsAFailedUpload(): void
    {
        $file = new UploadedFile('big.iso', 'application/octet-stream', 0, '', \UPLOAD_ERR_INI_SIZE);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not complete');

        $file->moveTo($this->tempPath('never'));
    }

    public function testContentsRejectsAFailedUpload(): void
    {
        $file = new UploadedFile('big.iso', 'application/octet-stream', 0, '', \UPLOAD_ERR_PARTIAL);

        $this->expectException(RuntimeException::class);

        $file->contents();
    }

    private function uploadOf(string $contents): UploadedFile
    {
        $tmp = $this->tempPath('upload');
        \file_put_contents($tmp, $contents);

        return new UploadedFile('upload.txt', 'text/plain', \strlen($contents), $tmp);
    }

    private function tempPath(string $prefix): string
    {
        $path = \sys_get_temp_dir() . '/relayer-' . $prefix . '-' . \bin2hex(\random_bytes(6));
        $this->paths[] = $path;

        return $path;
    }
}
