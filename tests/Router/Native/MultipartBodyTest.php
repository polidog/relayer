<?php

declare(strict_types=1);

namespace Polidog\Relayer\Tests\Router\Native;

use PHPUnit\Framework\TestCase;
use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\UploadedFile;
use Polidog\Relayer\Router\Native\MultipartBody;
use Polidog\Relayer\Router\Native\NativeProcessException;

final class MultipartBodyTest extends TestCase
{
    private const CONTENT_TYPE = 'multipart/form-data; boundary=XBOUNDARY';

    private string $tmp = '';

    protected function tearDown(): void
    {
        if ('' !== $this->tmp) {
            @\unlink($this->tmp);
        }
    }

    public function testARawBodyIsReturnedAsIs(): void
    {
        $request = new Request('POST', '/', headers: ['content-type' => 'application/json'], body: '{"a":1}');

        self::assertSame('{"a":1}', MultipartBody::for($request));
    }

    public function testNonMultipartEmptyBodyStaysEmpty(): void
    {
        self::assertSame('', MultipartBody::for(new Request('GET', '/', body: '')));
    }

    public function testRebuildsFieldsAndFilesUnderTheClientsBoundary(): void
    {
        $this->tmp = (string) \tempnam(\sys_get_temp_dir(), 'mp');
        \file_put_contents($this->tmp, "bytes\r\n");

        $request = new Request(
            'POST',
            '/',
            post: ['title' => 'hi', 'tags' => ['a', 'b'], 'meta' => ['k' => 'v']],
            headers: ['content-type' => self::CONTENT_TYPE],
            files: ['doc' => new UploadedFile('a"b.txt', 'text/plain', 7, $this->tmp)],
            body: '',
        );

        self::assertSame(
            "--XBOUNDARY\r\nContent-Disposition: form-data; name=\"title\"\r\n\r\nhi\r\n"
            . "--XBOUNDARY\r\nContent-Disposition: form-data; name=\"tags[]\"\r\n\r\na\r\n"
            . "--XBOUNDARY\r\nContent-Disposition: form-data; name=\"tags[]\"\r\n\r\nb\r\n"
            . "--XBOUNDARY\r\nContent-Disposition: form-data; name=\"meta[k]\"\r\n\r\nv\r\n"
            . "--XBOUNDARY\r\nContent-Disposition: form-data; name=\"doc\"; filename=\"a%22b.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\nbytes\r\n\r\n"
            . "--XBOUNDARY--\r\n",
            MultipartBody::for($request),
        );
    }

    public function testQuotedBoundaryAndAMultiFileFieldAreUnderstood(): void
    {
        $this->tmp = (string) \tempnam(\sys_get_temp_dir(), 'mp');
        \file_put_contents($this->tmp, 'x');

        $request = new Request(
            'POST',
            '/',
            headers: ['content-type' => 'multipart/form-data; charset=utf-8; boundary="Q B"'],
            files: ['docs' => [new UploadedFile('1', '', 1, $this->tmp)]],
            body: '',
        );

        $body = MultipartBody::for($request);

        self::assertStringStartsWith("--Q B\r\nContent-Disposition: form-data; name=\"docs[]\"; filename=\"1\"\r\nContent-Type: application/octet-stream\r\n", $body);
        self::assertStringEndsWith("--Q B--\r\n", $body);
    }

    public function testAnEmptyFileInputIsSkipped(): void
    {
        $request = new Request(
            'POST',
            '/',
            post: ['a' => '1'],
            headers: ['content-type' => self::CONTENT_TYPE],
            files: ['doc' => new UploadedFile('', '', 0, '', \UPLOAD_ERR_NO_FILE)],
            body: '',
        );

        self::assertStringNotContainsString('doc', MultipartBody::for($request));
    }

    public function testAnUploadPhpRejectedRaises(): void
    {
        $request = new Request(
            'POST',
            '/',
            headers: ['content-type' => self::CONTENT_TYPE],
            files: ['doc' => new UploadedFile('big', '', 0, '', \UPLOAD_ERR_INI_SIZE)],
            body: '',
        );

        $this->expectException(NativeProcessException::class);
        $this->expectExceptionMessage('upload "doc"');
        MultipartBody::for($request);
    }

    public function testABodyPhpDroppedRaises(): void
    {
        $request = new Request(
            'POST',
            '/',
            headers: ['content-type' => self::CONTENT_TYPE, 'content-length' => '999999'],
            body: '',
        );

        $this->expectException(NativeProcessException::class);
        $this->expectExceptionMessage('post_max_size');
        MultipartBody::for($request);
    }
}
