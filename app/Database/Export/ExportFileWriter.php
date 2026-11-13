<?php

namespace App\Database\Export;

use App\Database\Exceptions\DatabaseOperationException;
use DeflateContext;
use Illuminate\Support\Facades\File;

/**
 * Writes an export file: created exclusively (mode 0600, never an existing
 * file or symlink), optionally gzip-compressed incrementally, and deleted
 * again by abort(). Nothing is accumulated in memory.
 */
final class ExportFileWriter
{
    /** Uncompressed bytes passed to write(). */
    public int $bytesWritten = 0;

    /** Bytes on disk. */
    public int $fileBytes = 0;

    /** @var resource */
    private $handle;

    private function __construct(
        private readonly string $path,
        private readonly ?DeflateContext $deflate,
    ) {}

    /**
     * @throws DatabaseOperationException
     */
    public static function create(string $path, bool $gzip): self
    {
        File::ensureDirectoryExists(dirname($path), 0700);
        $previousUmask = umask(0077);
        $handle = @fopen($path, 'xb');
        umask($previousUmask);

        if ($handle === false) {
            throw new DatabaseOperationException('The export file could not be created.');
        }

        $deflate = null;

        if ($gzip) {
            $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);

            if ($deflate === false) {
                fclose($handle);
                File::delete($path);

                throw new DatabaseOperationException('Gzip compression is not available (the zlib extension is missing).');
            }
        }

        $writer = new self($path, $deflate);
        $writer->handle = $handle;

        return $writer;
    }

    /**
     * @throws DatabaseOperationException when the disk is full
     */
    public function write(string $data): void
    {
        if ($data === '') {
            return;
        }

        $this->bytesWritten += strlen($data);
        $this->put($this->deflate === null ? $data : (string) deflate_add($this->deflate, $data, ZLIB_NO_FLUSH));
    }

    /**
     * Flush the compressor and close the file.
     *
     * @throws DatabaseOperationException
     */
    public function finish(): void
    {
        if ($this->deflate !== null) {
            $this->put((string) deflate_add($this->deflate, '', ZLIB_FINISH));
        }

        fclose($this->handle);
    }

    /**
     * Close (if still open) and delete the partial file.
     */
    public function abort(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        File::delete($this->path);
    }

    /**
     * @throws DatabaseOperationException
     */
    private function put(string $data): void
    {
        if ($data === '') {
            return;
        }

        $written = fwrite($this->handle, $data);

        if ($written !== strlen($data)) {
            throw new DatabaseOperationException('Writing the export file failed. The disk may be full.');
        }

        $this->fileBytes += $written;
    }
}
