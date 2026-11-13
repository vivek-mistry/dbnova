<?php

namespace App\Services\Import;

use App\Enums\UploadStatus;
use App\Models\Upload;
use App\Models\User;
use Illuminate\Support\Facades\File;

/**
 * Stores chunked uploads. Each chunk is streamed straight to its byte
 * offset in the upload file (never held in memory as a whole), and an
 * empty marker file records that it arrived, so concurrent or repeated
 * chunk requests are safe and a resumed upload skips what it already sent.
 */
class UploadStore
{
    /**
     * Accepted file name endings, longest first, and the format each means.
     *
     * @var array<string, 'sql'|'gz'|'zip'>
     */
    private const array EXTENSIONS = ['.sql.gz' => 'gz', '.sql' => 'sql', '.gz' => 'gz', '.zip' => 'zip'];

    /** Unfinished uploads one user may have at a time (free disk is checked, not reserved). */
    public const int MAX_OPEN_UPLOADS = 3;

    /**
     * The format of a file name, or null when it is not accepted.
     *
     * @return 'sql'|'gz'|'zip'|null
     */
    public static function formatOf(string $name): ?string
    {
        foreach (self::EXTENSIONS as $extension => $format) {
            if (str_ends_with(strtolower($name), $extension)) {
                return $format;
            }
        }

        return null;
    }

    /**
     * @throws UploadException when the file type, size or free disk space rules it out
     */
    public function create(User $user, string $name, int $size): Upload
    {
        $format = self::formatOf($name) ?? throw new UploadException('Only .sql, .sql.gz and .zip files can be imported.');
        $maxBytes = config()->integer('dbnova.limits.max_upload_bytes');

        if ($size < 1 || $size > $maxBytes) {
            throw new UploadException('The file must be between 1 byte and '.self::humanBytes($maxBytes).'.');
        }

        $open = Upload::query()->whereBelongsTo($user)->where('status', UploadStatus::Uploading)->count();

        if ($open >= self::MAX_OPEN_UPLOADS) {
            throw new UploadException(sprintf('You already have %d unfinished uploads. Finish or cancel one first.', $open));
        }

        $directory = config()->string('dbnova.paths.uploads');
        File::ensureDirectoryExists($directory, 0700);

        $needed = $size + config()->integer('dbnova.limits.min_free_disk_bytes');
        $free = (int) disk_free_space($directory);

        if ($free < $needed) {
            throw new UploadException(sprintf(
                'Not enough free disk space: the upload needs %s (the file plus a %s safety margin) but only %s is free.',
                self::humanBytes($needed),
                self::humanBytes(config()->integer('dbnova.limits.min_free_disk_bytes')),
                self::humanBytes($free),
            ));
        }

        $chunkSize = config()->integer('dbnova.limits.upload_chunk_bytes');

        $upload = Upload::query()->create([
            'user_id' => $user->id,
            'original_name' => mb_substr(basename(str_replace('\\', '/', $name)), 0, 255),
            'format' => $format,
            'size' => $size,
            'chunk_size' => $chunkSize,
            'total_chunks' => (int) ceil($size / $chunkSize),
            'status' => UploadStatus::Uploading,
        ]);

        File::ensureDirectoryExists($upload->chunkDirectory(), 0700);
        touch($upload->path());
        chmod($upload->path(), 0600);

        return $upload;
    }

    /**
     * Stream one chunk from $input to its offset in the upload file.
     *
     * @param  resource  $input
     *
     * @throws UploadException when the chunk is out of range or has the wrong length
     */
    public function writeChunk(Upload $upload, int $index, $input): void
    {
        if ($upload->status !== UploadStatus::Uploading) {
            throw new UploadException('This upload is no longer accepting data.');
        }

        if ($index < 0 || $index >= $upload->total_chunks) {
            throw new UploadException('That chunk is outside the file.');
        }

        $expected = $upload->chunkLength($index);
        // 'r+b' fails if the file was deleted (upload cancelled) instead of re-creating it.
        $output = is_file($upload->path()) ? @fopen($upload->path(), 'r+b') : false;

        if ($output === false) {
            throw new UploadException('This upload is no longer accepting data.');
        }

        try {
            fseek($output, $index * $upload->chunk_size);
            // Never write past this chunk's range; extra bytes are detected below without being written.
            $copied = stream_copy_to_stream($input, $output, $expected);
            fflush($output);
        } finally {
            fclose($output);
        }

        $oversized = ! feof($input) && fread($input, 1) !== '';

        if ($copied !== $expected || $oversized) {
            throw new UploadException(sprintf(
                'Chunk %d should be %d bytes but %s arrived. It will be sent again.',
                $index,
                $expected,
                $oversized ? 'more' : (string) (int) $copied,
            ));
        }

        if (! is_dir($upload->chunkDirectory())) {
            throw new UploadException('This upload is no longer accepting data.');
        }

        touch($upload->chunkDirectory().'/'.$index);

        if (count($this->receivedChunks($upload)) === $upload->total_chunks) {
            Upload::query()->whereKey($upload->id)->where('status', UploadStatus::Uploading)
                ->update(['status' => UploadStatus::Uploaded, 'completed_at' => now()]);
            $upload->refresh();
        }
    }

    /**
     * The indexes of the chunks that have arrived, in order.
     *
     * @return list<int>
     */
    public function receivedChunks(Upload $upload): array
    {
        if ($upload->status === UploadStatus::Uploaded) {
            return range(0, $upload->total_chunks - 1);
        }

        $names = @scandir($upload->chunkDirectory());

        if ($names === false) {
            return [];
        }

        $indexes = array_values(array_map('intval', array_filter($names, fn (string $name): bool => ctype_digit($name))));
        sort($indexes);

        return $indexes;
    }

    /**
     * Stop an upload and delete what it stored.
     */
    public function cancel(Upload $upload): void
    {
        $this->deleteFiles($upload);
        $upload->update(['status' => UploadStatus::Cancelled]);
    }

    /**
     * Delete the upload's file and chunk markers.
     */
    public function deleteFiles(Upload $upload): void
    {
        File::delete($upload->path());
        File::deleteDirectory($upload->chunkDirectory());
    }

    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $unit = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return ($unit === 0 ? (string) $bytes : number_format($value, 1)).' '.$units[$unit];
    }
}
