<?php

namespace App\Database\Import;

use InflateContext;
use ZipArchive;

/**
 * Reads the SQL text of an uploaded file in bounded pieces, whatever its
 * format, without extracting or loading it:
 *
 * - sql: read directly.
 * - gz:  inflated incrementally (multi-member files supported). Progress
 *        is counted in compressed bytes, so it compares with the file size.
 * - zip: the single .sql entry is streamed from the archive. Entries with
 *        unsafe paths, symlinks or encryption are refused. Progress is
 *        counted in uncompressed bytes of that entry.
 */
final class SourceStream
{
    /** Compressed bytes inflated per step; bounds memory even for highly compressible data. */
    private const int INFLATE_STEP = 8192;

    /** More entries than this are refused before ZipArchive reads the archive's directory. */
    private const int MAX_ZIP_ENTRIES = 1000;

    /** @var resource|null */
    private $handle;

    private ?InflateContext $inflate = null;

    private ?ZipArchive $zip = null;

    /** Inflated text not yet returned (gz). */
    private string $output = '';

    /** Compressed bytes read from disk but not yet inflated (gz). */
    private string $pending = '';

    private int $position = 0;

    private bool $finished = false;

    /**
     * @param  resource  $handle
     */
    private function __construct($handle, private string $format, private int $total)
    {
        $this->handle = $handle;
    }

    /**
     * @param  'sql'|'gz'|'zip'  $format
     *
     * @throws SourceException when the file is missing, of another type, or an unsafe archive
     */
    public static function open(string $path, string $format): self
    {
        if (! is_file($path)) {
            throw new SourceException('The uploaded file is no longer on disk.');
        }

        if ($format === 'zip') {
            return self::openZip($path);
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new SourceException('The uploaded file could not be opened.');
        }

        $source = new self($handle, $format, (int) filesize($path));

        if ($format === 'gz') {
            if (fread($handle, 2) !== "\x1f\x8b") {
                fclose($handle);

                throw new SourceException('The file is named .gz but is not gzip-compressed.');
            }

            rewind($handle);
            $source->inflate = self::newInflateContext();
        }

        return $source;
    }

    /**
     * Up to $bytes of SQL text; an empty string only at the end.
     *
     * @param  int<1, max>  $bytes
     *
     * @throws SourceException when the file is corrupt or truncated
     */
    public function read(int $bytes): string
    {
        if ($this->finished) {
            return '';
        }

        $data = $this->format === 'gz' ? $this->readInflated($bytes) : $this->readPlain($bytes);

        if ($data === '') {
            $this->finished = true;
        }

        return $data;
    }

    public function eof(): bool
    {
        return $this->finished;
    }

    /**
     * Progress so far, in the same unit as total().
     */
    public function position(): int
    {
        return $this->position;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        $this->handle = null;
        $this->zip?->close();
        $this->zip = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private static function openZip(string $path): self
    {
        // ZipArchive loads the whole central directory on open; refuse archives with many entries first.
        $entryCount = self::zipEntryCount($path);

        if ($entryCount !== null && $entryCount > self::MAX_ZIP_ENTRIES) {
            throw new SourceException(sprintf('The zip archive contains %d entries; it must contain exactly one .sql file.', $entryCount));
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new SourceException('The file is named .zip but is not a readable zip archive.');
        }

        $entries = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);

            if ($stat === false) {
                continue;
            }

            $name = $stat['name'];

            if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || str_starts_with(basename($name), '._')) {
                continue; // Folders and macOS metadata.
            }

            if (str_contains($name, "\0") || str_starts_with($name, '/') || str_starts_with($name, '\\') || preg_match('/^[A-Za-z]:/', $name) === 1
                || in_array('..', preg_split('#[/\\\\]#', $name) ?: [], true)) {
                $zip->close();

                throw new SourceException('The zip archive contains an unsafe path ('.mb_strimwidth($name, 0, 80, '…').') and was refused.');
            }

            $operatingSystem = 0;
            $attributes = 0;
            $zip->getExternalAttributesIndex($index, $operatingSystem, $attributes);

            if ($operatingSystem === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                $zip->close();

                throw new SourceException('The zip archive contains a symbolic link and was refused.');
            }

            if ($stat['encryption_method'] !== 0) {
                $zip->close();

                throw new SourceException('The zip archive is password-protected. Upload an unencrypted archive.');
            }

            $entries[] = $stat;
        }

        if (count($entries) !== 1 || ! str_ends_with(strtolower($entries[0]['name']), '.sql')) {
            $zip->close();

            throw new SourceException(sprintf('The zip archive must contain exactly one .sql file; it contains %d file(s).', count($entries)));
        }

        $stream = $zip->getStreamIndex($entries[0]['index']);

        if ($stream === false) {
            $zip->close();

            throw new SourceException('The .sql file inside the zip archive could not be read.');
        }

        $source = new self($stream, 'zip', (int) $entries[0]['size']);
        $source->zip = $zip;

        return $source;
    }

    /**
     * The number of entries recorded in the archive's end-of-central-directory
     * record (zip64 aware), or null when it cannot be found.
     */
    private static function zipEntryCount(string $path): ?int
    {
        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');

        if ($handle === false || $size < 22) {
            return null;
        }

        try {
            // The record is 22 bytes plus a comment of up to 65,535 bytes at the end of the file.
            $tailLength = max(22, min($size, 22 + 65535));
            fseek($handle, $size - $tailLength);
            $tail = (string) fread($handle, $tailLength);
            $record = strrpos($tail, "PK\x05\x06");

            if ($record === false || strlen($tail) < $record + 22) {
                return null;
            }

            $count = unpack('v', $tail, $record + 10)[1] ?? null;

            if ($count !== 0xFFFF || $record < 20 || substr($tail, $record - 20, 4) !== "PK\x06\x07") {
                return is_int($count) ? $count : null;
            }

            // Zip64: the locator before the record points at the zip64 end-of-central-directory record.
            $zip64Offset = unpack('P', $tail, $record - 20 + 8)[1] ?? null;

            if (! is_int($zip64Offset) || $zip64Offset < 0 || $zip64Offset > $size - 56) {
                return null;
            }

            fseek($handle, $zip64Offset);
            $zip64 = (string) fread($handle, 56);

            if (! str_starts_with($zip64, "PK\x06\x06")) {
                return null;
            }

            $total = unpack('P', $zip64, 32)[1] ?? null;

            return is_int($total) ? $total : null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  int<1, max>  $bytes
     */
    private function readPlain(int $bytes): string
    {
        if (! is_resource($this->handle) || feof($this->handle)) {
            $this->assertComplete();

            return '';
        }

        $data = fread($this->handle, $bytes);

        if ($data === false) {
            throw new SourceException('Reading the uploaded file failed.');
        }

        $this->position += strlen($data);

        if ($data === '') {
            $this->assertComplete();
        }

        return $data;
    }

    /**
     * A zip entry that ends early is corrupt; a plain file is whatever it is.
     */
    private function assertComplete(): void
    {
        if ($this->format === 'zip' && $this->position !== $this->total) {
            throw new SourceException('The .sql file inside the zip archive is corrupt or truncated.');
        }
    }

    private function readInflated(int $bytes): string
    {
        while (strlen($this->output) < $bytes) {
            if ($this->pending === '') {
                $chunk = is_resource($this->handle) ? fread($this->handle, 65536) : '';

                if ($chunk === false) {
                    throw new SourceException('Reading the uploaded file failed.');
                }

                if ($chunk === '') {
                    if ($this->inflate !== null && inflate_get_status($this->inflate) !== ZLIB_STREAM_END && $this->position > 0) {
                        throw new SourceException('The .gz file is truncated: it ends in the middle of the compressed data.');
                    }

                    break;
                }

                $this->pending = $chunk;
            }

            if ($this->inflate === null) {
                // Between gzip members: only another member (or zero padding) may follow.
                if (trim($this->pending, "\0") === '') {
                    $this->position += strlen($this->pending);
                    $this->pending = '';

                    continue;
                }

                if (! str_starts_with($this->pending, "\x1f\x8b") && strlen($this->pending) >= 2) {
                    throw new SourceException('The .gz file has unexpected data after the compressed stream.');
                }

                $this->inflate = self::newInflateContext();
            }

            $piece = substr($this->pending, 0, self::INFLATE_STEP);
            $readBefore = inflate_get_read_len($this->inflate);
            $inflated = @inflate_add($this->inflate, $piece, ZLIB_SYNC_FLUSH);

            if ($inflated === false) {
                throw new SourceException('The .gz file is corrupt and could not be decompressed.');
            }

            $used = inflate_get_read_len($this->inflate) - $readBefore;
            $this->pending = substr($this->pending, $used);
            $this->position += $used;
            $this->output .= $inflated;

            if (inflate_get_status($this->inflate) === ZLIB_STREAM_END) {
                $this->inflate = null;
            } elseif ($used === 0 && $inflated === '') {
                throw new SourceException('The .gz file is corrupt and could not be decompressed.');
            }
        }

        $data = substr($this->output, 0, $bytes);
        $this->output = substr($this->output, strlen($data));

        return $data;
    }

    private static function newInflateContext(): InflateContext
    {
        $context = inflate_init(ZLIB_ENCODING_GZIP);

        if ($context === false) {
            throw new SourceException('The zlib extension could not start decompressing.');
        }

        return $context;
    }
}
