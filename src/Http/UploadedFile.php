<?php

declare(strict_types=1);

namespace Polidog\Relayer\Http;

use RuntimeException;

/**
 * One entry of the upload table, normalized out of `$_FILES` so app code
 * never has to read that superglobal (or remember its two different array
 * shapes for single vs. `name[]` uploads).
 *
 * Built by {@see Request::fromGlobals()}; reachable as
 * `$request->file('avatar')` / `$request->files()`.
 */
final readonly class UploadedFile
{
    public function __construct(
        public string $clientName,
        public string $clientMimeType,
        public int $size,
        public string $tmpName,
        public int $error = \UPLOAD_ERR_OK,
    ) {}

    /**
     * Build from one `$_FILES` leaf (the five-key shape PHP produces).
     *
     * @param array<string, mixed> $file
     */
    public static function fromArray(array $file): self
    {
        return new self(
            clientName: \is_string($file['name'] ?? null) ? $file['name'] : '',
            clientMimeType: \is_string($file['type'] ?? null) ? $file['type'] : '',
            size: \is_int($file['size'] ?? null) ? $file['size'] : 0,
            tmpName: \is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '',
            error: \is_int($file['error'] ?? null) ? $file['error'] : \UPLOAD_ERR_NO_FILE,
        );
    }

    /**
     * True when PHP accepted the upload and a temp file is present. Always
     * check this before {@see moveTo()} — a form field left empty arrives as
     * `UPLOAD_ERR_NO_FILE`, which is not an exception-worthy condition.
     */
    public function isValid(): bool
    {
        return \UPLOAD_ERR_OK === $this->error && '' !== $this->tmpName;
    }

    /**
     * Move the temp file to its final location.
     *
     * Rejects anything PHP didn't put there itself (`is_uploaded_file`), so a
     * forged `tmp_name` can never make this relocate an arbitrary server file.
     *
     * @throws RuntimeException when the upload failed, was not a real upload,
     *                          or the move itself failed
     */
    public function moveTo(string $targetPath): void
    {
        if (!$this->isValid()) {
            throw new RuntimeException(\sprintf(
                'Cannot move upload "%s": it did not complete (error %d).',
                $this->clientName,
                $this->error,
            ));
        }

        // Under the CLI SAPI (tests, workers driving a synthetic request)
        // there is no PHP-managed upload table, so is_uploaded_file() is
        // always false and move_uploaded_file() always fails. Fall back to a
        // plain rename there — the guard only has teeth under a real SAPI,
        // which is the only place an attacker-supplied tmp_name can occur.
        $moved = 'cli' === \PHP_SAPI
            ? \rename($this->tmpName, $targetPath)
            : (\is_uploaded_file($this->tmpName) && \move_uploaded_file($this->tmpName, $targetPath));

        if (!$moved) {
            throw new RuntimeException(\sprintf(
                'Failed to move upload "%s" to "%s".',
                $this->clientName,
                $targetPath,
            ));
        }
    }

    /**
     * Read the uploaded bytes without moving the file.
     *
     * @throws RuntimeException when the upload failed or is unreadable
     */
    public function contents(): string
    {
        if (!$this->isValid()) {
            throw new RuntimeException(\sprintf(
                'Cannot read upload "%s": it did not complete (error %d).',
                $this->clientName,
                $this->error,
            ));
        }

        $contents = \file_get_contents($this->tmpName);

        if (false === $contents) {
            throw new RuntimeException(\sprintf('Failed to read upload "%s".', $this->clientName));
        }

        return $contents;
    }
}
