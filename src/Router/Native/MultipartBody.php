<?php

declare(strict_types=1);

namespace Polidog\Relayer\Router\Native;

use Polidog\Relayer\Http\Request;
use Polidog\Relayer\Http\UploadedFile;

/**
 * The request body a native handler should receive on its stdin.
 *
 * For almost every request that is simply {@see Request::body()}. The
 * exception is `multipart/form-data`: PHP consumes that body itself to
 * fill `$_POST` / `$_FILES`, and `php://input` is then empty, so a Go
 * handler would see a multipart request with no parts and no error.
 * The body is therefore rebuilt from what PHP parsed, under the boundary
 * the client's `Content-Type` already declares (the header is forwarded
 * unchanged, so `r.ParseMultipartForm` just works).
 *
 * The rebuilt body is equivalent, not identical: fields come before files,
 * and PHP's own limits (`upload_max_filesize`, `post_max_size`,
 * `max_file_uploads`, …) have already applied. An upload PHP rejected
 * raises instead of quietly vanishing, as does a body PHP dropped whole.
 */
final class MultipartBody
{
    /**
     * @throws NativeProcessException when PHP rejected an upload or dropped the body
     */
    public static function for(Request $request): string
    {
        $body = $request->body();
        if ('' !== $body) {
            return $body;
        }

        $boundary = self::boundary($request->header('content-type') ?? '');
        if (null === $boundary) {
            return '';
        }

        $parts = '';
        foreach (self::flatten($request->allPost()) as [$name, $value]) {
            $parts .= self::fieldPart($boundary, $name, $value);
        }
        foreach ($request->files() as $name => $files) {
            if (\is_array($files)) {
                foreach ($files as $file) {
                    $parts .= self::filePart($boundary, $name . '[]', $file);
                }
            } else {
                $parts .= self::filePart($boundary, $name, $files);
            }
        }

        if ('' === $parts) {
            // A request that announced a body but left nothing behind: PHP
            // discards everything past post_max_size.
            if ((int) ($request->header('content-length') ?? '0') > 0 && [] === $request->files()) {
                throw new NativeProcessException(
                    'multipart request body was dropped by PHP (is it larger than post_max_size?)',
                );
            }

            return '';
        }

        return $parts . '--' . $boundary . "--\r\n";
    }

    private static function boundary(string $contentType): ?string
    {
        if (1 !== \preg_match('#^\s*multipart/form-data\s*;(?:.*;)?\s*boundary=(?:"([^"]+)"|([^\s;]+))#i', $contentType, $m)) {
            return null;
        }

        return '' !== $m[1] ? $m[1] : $m[2];
    }

    /**
     * PHP's nested form array back to `name[key]` / `name[]` pairs.
     *
     * @param array<array-key, mixed> $values
     *
     * @return list<array{string, string}>
     */
    private static function flatten(array $values, ?string $prefix = null): array
    {
        $isList = \array_is_list($values);

        $pairs = [];
        foreach ($values as $key => $value) {
            $name = match (true) {
                null === $prefix => (string) $key,
                $isList => $prefix . '[]',
                default => $prefix . '[' . $key . ']',
            };

            if (\is_array($value)) {
                $pairs = [...$pairs, ...self::flatten($value, $name)];
            } elseif (\is_string($value)) {
                $pairs[] = [$name, $value];
            }
        }

        return $pairs;
    }

    private static function fieldPart(string $boundary, string $name, string $value): string
    {
        return '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="' . self::quote($name) . "\"\r\n\r\n"
            . $value . "\r\n";
    }

    /**
     * @throws NativeProcessException when PHP rejected this upload
     */
    private static function filePart(string $boundary, string $name, UploadedFile $file): string
    {
        // A file input left empty is not an upload at all.
        if (\UPLOAD_ERR_NO_FILE === $file->error) {
            return '';
        }

        $contents = $file->isValid() ? @\file_get_contents($file->tmpName) : false;
        if (false === $contents) {
            throw new NativeProcessException(\sprintf(
                'upload "%s" could not be passed to the handler (PHP upload error %d)',
                $name,
                $file->error,
            ));
        }

        $type = '' !== $file->clientMimeType ? $file->clientMimeType : 'application/octet-stream';

        return '--' . $boundary . "\r\n"
            . 'Content-Disposition: form-data; name="' . self::quote($name)
            . '"; filename="' . self::quote($file->clientName) . "\"\r\n"
            . 'Content-Type: ' . \str_replace(["\r", "\n"], '', $type) . "\r\n\r\n"
            . $contents . "\r\n";
    }

    /**
     * The escaping browsers apply to `name` / `filename` (WHATWG form
     * encoding), so a crafted name cannot break out of its header.
     */
    private static function quote(string $value): string
    {
        return \str_replace(["\r", "\n", '"'], ['%0D', '%0A', '%22'], $value);
    }
}
