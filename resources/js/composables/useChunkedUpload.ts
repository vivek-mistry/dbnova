import { computed, onBeforeUnmount, reactive } from 'vue';
import {
    chunk as chunkRoute,
    destroy as destroyRoute,
    show as showRoute,
    store as storeRoute,
} from '@/routes/uploads';
import type { UploadSummary } from '@/types';

export type ChunkedUploadState = {
    phase: 'idle' | 'uploading' | 'paused' | 'uploaded' | 'error' | 'cancelled';
    uploadId: string | null;
    fileName: string | null;
    size: number;
    totalChunks: number;
    receivedChunks: number;
    bytesPerSecond: number;
    resumed: boolean;
    error: string | null;
};

/** Chunks in flight at once: enough to hide latency, small enough to stay polite. */
const PARALLEL_CHUNKS = 3;
const MAX_ATTEMPTS = 6;

class UploadFailed extends Error {}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function storageKey(file: File): string {
    return `dbnova:upload:${file.name}:${file.size}:${file.lastModified}`;
}

function remembered(file: File): string | null {
    try {
        return window.localStorage.getItem(storageKey(file));
    } catch {
        return null;
    }
}

function remember(file: File, id: string | null): void {
    try {
        if (id === null) {
            window.localStorage.removeItem(storageKey(file));
        } else {
            window.localStorage.setItem(storageKey(file), id);
        }
    } catch {
        // Resuming after a reload is a convenience; uploads work without it.
    }
}

async function request<T>(
    method: string,
    url: string,
    body?: BodyInit,
    signal?: AbortSignal,
    contentType = 'application/json',
): Promise<T> {
    const response = await fetch(url, {
        method,
        body,
        signal,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': contentType,
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
    });

    if (!response.ok) {
        const data = (await response.json().catch(() => ({}))) as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        const message =
            Object.values(data.errors ?? {})[0]?.[0] ??
            data.message ??
            (response.status === 419
                ? 'Your session expired. Reload the page to continue the upload.'
                : `The server answered ${response.status}.`);
        const error = new UploadFailed(message);
        // Retry only what may succeed later: server errors and rate limits.
        (error as UploadFailed & { retryable: boolean }).retryable =
            response.status >= 500 || response.status === 429;

        throw error;
    }

    return (await response.json()) as T;
}

function sleep(milliseconds: number, signal: AbortSignal): Promise<void> {
    return new Promise((resolve, reject) => {
        const timer = window.setTimeout(resolve, milliseconds);
        signal.addEventListener('abort', () => {
            window.clearTimeout(timer);
            reject(new DOMException('Aborted', 'AbortError'));
        });
    });
}

/**
 * Uploads a file in chunks that survive network errors and page reloads:
 * the server keeps the received chunks, and the upload id is remembered
 * per file, so choosing the same file again resumes where it stopped.
 */
export function useChunkedUpload() {
    const state = reactive<ChunkedUploadState>({
        phase: 'idle',
        uploadId: null,
        fileName: null,
        size: 0,
        totalChunks: 0,
        receivedChunks: 0,
        bytesPerSecond: 0,
        resumed: false,
        error: null,
    });

    let controller: AbortController | null = null;
    let currentFile: File | null = null;

    const progress = computed(() =>
        state.totalChunks === 0 ? 0 : state.receivedChunks / state.totalChunks,
    );

    async function open(
        file: File,
        signal: AbortSignal,
    ): Promise<UploadSummary> {
        const previousId = remembered(file);

        if (previousId !== null) {
            try {
                const previous = await request<UploadSummary>(
                    'GET',
                    showRoute.url(previousId),
                    undefined,
                    signal,
                );

                if (
                    previous.status === 'uploading' ||
                    previous.status === 'uploaded'
                ) {
                    state.resumed = previous.received.length > 0;

                    return previous;
                }
            } catch (error) {
                if ((error as Error).name === 'AbortError') {
                    throw error;
                }
                // Gone or expired: start a new upload below.
            }
        }

        const created = await request<UploadSummary>(
            'POST',
            storeRoute.url(),
            JSON.stringify({ name: file.name, size: file.size }),
            signal,
        );
        remember(file, created.id);

        return created;
    }

    async function sendChunk(
        file: File,
        upload: UploadSummary,
        index: number,
        signal: AbortSignal,
    ): Promise<void> {
        const start = index * upload.chunkSize;
        const blob = file.slice(
            start,
            Math.min(start + upload.chunkSize, file.size),
        );

        for (let attempt = 1; ; attempt++) {
            try {
                await request(
                    'PUT',
                    chunkRoute.url({ upload: upload.id, index }),
                    blob,
                    signal,
                    'application/octet-stream',
                );

                return;
            } catch (error) {
                const retryable =
                    !(error instanceof UploadFailed) ||
                    (error as UploadFailed & { retryable?: boolean })
                        .retryable === true;

                if (
                    (error as Error).name === 'AbortError' ||
                    !retryable ||
                    attempt >= MAX_ATTEMPTS
                ) {
                    throw error;
                }

                await sleep(1000 * 2 ** (attempt - 1), signal);
            }
        }
    }

    /**
     * Upload (or resume) the file. Resolves with the upload id once every
     * chunk is on the server, or null when paused, cancelled or failed.
     */
    async function start(file: File): Promise<string | null> {
        controller?.abort();
        controller = new AbortController();
        const signal = controller.signal;
        currentFile = file;

        Object.assign(state, {
            phase: 'uploading',
            fileName: file.name,
            size: file.size,
            error: null,
            resumed: false,
            bytesPerSecond: 0,
        });

        try {
            const upload = await open(file, signal);
            const received = new Set(upload.received);
            state.uploadId = upload.id;
            state.totalChunks = upload.totalChunks;
            state.receivedChunks = received.size;

            const queue = Array.from(
                { length: upload.totalChunks },
                (_, index) => index,
            ).filter((index) => !received.has(index));
            const startedAt = performance.now();
            let sentBytes = 0;

            const worker = async (): Promise<void> => {
                for (
                    let index = queue.shift();
                    index !== undefined;
                    index = queue.shift()
                ) {
                    await sendChunk(file, upload, index, signal);
                    state.receivedChunks++;
                    sentBytes += Math.min(
                        upload.chunkSize,
                        file.size - index * upload.chunkSize,
                    );
                    state.bytesPerSecond =
                        sentBytes / ((performance.now() - startedAt) / 1000);
                }
            };

            await Promise.all(Array.from({ length: PARALLEL_CHUNKS }, worker));

            state.phase = 'uploaded';
            remember(file, null);

            return upload.id;
        } catch (error) {
            if ((error as Error).name === 'AbortError') {
                return null;
            }

            controller.abort();
            state.phase = 'error';
            state.error =
                error instanceof UploadFailed
                    ? error.message
                    : 'The upload stopped because the network failed. Resume to continue where it stopped.';

            return null;
        }
    }

    function pause(): void {
        if (state.phase === 'uploading') {
            controller?.abort();
            state.phase = 'paused';
        }
    }

    function resume(): Promise<string | null> {
        return currentFile === null
            ? Promise.resolve(null)
            : start(currentFile);
    }

    async function cancel(): Promise<void> {
        controller?.abort();

        if (currentFile !== null) {
            remember(currentFile, null);
        }

        if (state.uploadId !== null) {
            await request('DELETE', destroyRoute.url(state.uploadId)).catch(
                () => undefined,
            );
        }

        Object.assign(state, {
            phase: 'cancelled',
            uploadId: null,
            receivedChunks: 0,
        });
    }

    function reset(): void {
        controller?.abort();
        currentFile = null;
        Object.assign(state, {
            phase: 'idle',
            uploadId: null,
            fileName: null,
            size: 0,
            totalChunks: 0,
            receivedChunks: 0,
            bytesPerSecond: 0,
            resumed: false,
            error: null,
        });
    }

    onBeforeUnmount(() => controller?.abort());

    return { state, progress, start, pause, resume, cancel, reset };
}
