<?php

namespace App\Http\Controllers;

use App\Enums\ImportStatus;
use App\Models\ImportJob;
use App\Models\Upload;
use App\Models\User;
use App\Services\Import\DiskFullException;
use App\Services\Import\UploadException;
use App\Services\Import\UploadStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chunked, resumable uploads (JSON). Chunks are raw request bodies streamed
 * to disk; PHP's upload_max_filesize only has to exceed one chunk.
 */
class UploadController extends Controller
{
    public function __construct(private UploadStore $store) {}

    /**
     * Start an upload after checking its type, size and free disk space.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $upload = $this->store->create($this->user($request), $validated['name'], (int) $validated['size']);
        } catch (UploadException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'errors' => ['file' => [$exception->getMessage()]]], 422);
        }

        return response()->json($this->status($upload), 201);
    }

    /**
     * Which chunks have arrived, so an interrupted upload can resume.
     */
    public function show(Request $request, Upload $upload): JsonResponse
    {
        $this->authorizeOwner($request, $upload);

        return response()->json($this->status($upload));
    }

    /**
     * Receive one chunk as the raw request body.
     */
    public function chunk(Request $request, Upload $upload, int $index): JsonResponse
    {
        $this->authorizeOwner($request, $upload);

        /** @var resource $body */
        $body = $request->getContent(asResource: true);

        try {
            $this->store->writeChunk($upload, $index, $body);
        } catch (DiskFullException $exception) {
            // 507 Insufficient Storage: the uploader stops retrying and shows the message.
            return response()->json(['message' => $exception->getMessage(), 'diskFull' => true], 507);
        } catch (UploadException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'status' => $upload->status->value,
            'receivedCount' => count($this->store->receivedChunks($upload)),
        ]);
    }

    /**
     * Cancel an upload and delete its data, unless an import is using it.
     */
    public function destroy(Request $request, Upload $upload): JsonResponse
    {
        $this->authorizeOwner($request, $upload);

        $inUse = ImportJob::query()->where('upload_id', $upload->id)
            ->whereIn('status', [ImportStatus::Pending, ImportStatus::Preflight, ImportStatus::Queued, ImportStatus::Importing])
            ->exists();

        if ($inUse) {
            return response()->json(['message' => 'An import is using this file. Cancel the import first.'], 409);
        }

        $this->store->cancel($upload);

        return response()->json(['status' => $upload->status->value]);
    }

    /**
     * @return array<string, mixed>
     */
    private function status(Upload $upload): array
    {
        return [...$upload->toSummary(), 'received' => $this->store->receivedChunks($upload)];
    }

    private function authorizeOwner(Request $request, Upload $upload): void
    {
        abort_unless($upload->user_id === $this->user($request)->id, 404);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
