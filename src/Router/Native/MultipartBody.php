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
 * raises instead of quietly vanishing, as does a body PHP dropped whole. A
 * form with nothing in it stays a valid multipart body (just the closing
 * boundary), because Go's parser treats a truly empty one as an error.
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

        $length = (int) ($request->header('content-length') ?? '0');

        if ('' === $parts) {
            // PHP discards everything past post_max_size. Anything smaller
            // that parsed to nothing really was an empty form (an empty
            // `FormData`, or only unselected file inputs), still a body.
            if (self::exceedsPostMaxSize($length)) {
                throw new NativeProcessException(
                    'multipart request body was dropped by PHP (it is larger than post_max_size)',
                );
            }

            if (0 === $length) {
                return '';
            }
        }

        return $parts . '--' . $boundary . "--\r\n";
    }

    private static function exceedsPostMaxSize(int $length): bool
    {
        $limit = \ini_parse_quantity((string) \ini_get('post_max_size'));

        return $limit > 0 && $length > $limit;
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
     * Escape for a quoted-string: `\` as the MIME parser on the Go side reads
     * it (left alone, a trailing one would swallow the closing quote), then
     * `"` and line breaks as browsers encode them, so a crafted name cannot
     * break out of its header.
     */
    private static function quote(string $value): string
    {
        return \str_replace(['\\', "\r", "\n", '"'], ['\\\\', '%0D', '%0A', '%22'], $value);
    }
}
