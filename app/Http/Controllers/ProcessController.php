<?php

namespace App\Http\Controllers;

use App\Database\Contracts\DatabaseAdapterInterface;
use App\Database\DTOs\ProcessInfo;
use App\Database\Exceptions\DatabaseOperationException;
use App\Database\Exceptions\ReadOnlyModeException;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The server's process list (polled by the page) and killing a thread's
 * query or connection. MySQL decides which threads an account may see
 * and kill; DBNova adds confirmation, the audit log and read-only mode.
 */
class ProcessController extends Controller
{
    public function __construct(private DatabaseAdapterInterface $adapter) {}

    public function index(): Response
    {
        return Inertia::render('processes/Index', [
            'processes' => Inertia::defer(fn (): array => $this->processList()),
        ]);
    }

    /**
     * Polled every few seconds while the page is open.
     */
    public function list(): JsonResponse
    {
        return response()->json($this->processList());
    }

    public function kill(Request $request, int $process, AuditLogger $auditLogger): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'in:query,connection'],
            'confirm' => ['accepted'],
        ]);
        $connection = $validated['mode'] === 'connection';

        $server = $this->adapter->server();

        try {
            // Look the thread up first: the audit entry names whose work was stopped.
            $target = collect($server->processes())->first(fn (ProcessInfo $info): bool => $info->id === $process);

            if ($target === null) {
                return response()->json(['message' => 'That process has already ended.'], 404);
            }

            $killed = $auditLogger->track(
                $connection ? 'process.kill' : 'process.kill-query',
                $target->database,
                fn (): bool => $server->kill($process, $connection),
                ['id' => $process, 'user' => $target->user, 'command' => $target->command, 'timeSeconds' => $target->timeSeconds],
            );
        } catch (ReadOnlyModeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        } catch (DatabaseOperationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'killed' => $killed,
            'message' => match (true) {
                ! $killed => 'That process had already ended.',
                $connection => "Connection {$process} was closed.",
                default => "The query on connection {$process} was stopped.",
            },
        ]);
    }

    /**
     * @return array{processes: list<array<string, mixed>>, error: string|null}
     */
    private function processList(): array
    {
        try {
            return ['processes' => array_map(fn (ProcessInfo $info): array => $info->toArray(), $this->adapter->server()->processes()), 'error' => null];
        } catch (DatabaseOperationException $exception) {
            return ['processes' => [], 'error' => $exception->getMessage()];
        }
    }
}
