import { useHttp } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { metrics as metricsRoute } from '@/routes/server';
import type { ServerMetrics } from '@/types';

/** Poll interval and how many samples are kept (5 s × 60 = 5 minutes). */
const POLL_MS = 5000;
const KEEP = 60;

/**
 * Polls the server's cumulative counters and turns them into chart
 * series. Rates are differences between consecutive samples, so the first
 * sample (and one after a server restart) has no rate. Polling pauses
 * while the browser tab is hidden.
 */
export function useServerMetrics() {
    const samples = ref<ServerMetrics[]>([]);
    const error = ref<string | null>(null);
    const http = useHttp<Record<string, never>, ServerMetrics>();
    let timer: number | undefined;

    async function poll(): Promise<void> {
        window.clearTimeout(timer);

        try {
            const sample = await http.get(metricsRoute.url());
            const previous = samples.value[samples.value.length - 1];

            // A lower uptime means the server restarted: earlier counters no longer compare.
            samples.value =
                previous && sample.uptimeSeconds < previous.uptimeSeconds
                    ? [sample]
                    : [...samples.value, sample].slice(-KEEP);
            error.value = null;
        } catch {
            error.value = 'The server could not be reached. Retrying…';
        }

        schedule();
    }

    function schedule(): void {
        window.clearTimeout(timer);

        if (document.visibilityState === 'visible') {
            timer = window.setTimeout(poll, POLL_MS);
        }
    }

    function onVisibility(): void {
        if (document.visibilityState === 'visible') {
            void poll();
        } else {
            window.clearTimeout(timer);
        }
    }

    onMounted(() => {
        document.addEventListener('visibilitychange', onVisibility);
        void poll();
    });

    onBeforeUnmount(() => {
        window.clearTimeout(timer);
        document.removeEventListener('visibilitychange', onVisibility);
    });

    const timestamps = computed(() =>
        samples.value.map((s) => Math.round(s.takenAt * 1000)),
    );

    /** Per-second rate of a counter between consecutive samples. */
    function rate(pick: (s: ServerMetrics) => number): (number | null)[] {
        return samples.value.map((sample, i) => {
            const previous = samples.value[i - 1];

            if (!previous) {
                return null;
            }

            const seconds = sample.takenAt - previous.takenAt;
            const delta = pick(sample) - pick(previous);

            return seconds > 0 && delta >= 0 ? delta / seconds : null;
        });
    }

    const queriesPerSecond = computed(() => rate((s) => s.questions));
    const connected = computed(() =>
        samples.value.map((s) => s.threadsConnected),
    );
    const running = computed(() => samples.value.map((s) => s.threadsRunning));
    const bufferPoolUsed = computed(() =>
        samples.value.map((s) =>
            s.bufferPoolPagesTotal > 0
                ? ((s.bufferPoolPagesTotal - s.bufferPoolPagesFree) /
                      s.bufferPoolPagesTotal) *
                  100
                : null,
        ),
    );
    const latest = computed(
        () => samples.value[samples.value.length - 1] ?? null,
    );

    return {
        samples,
        error,
        timestamps,
        queriesPerSecond,
        connected,
        running,
        bufferPoolUsed,
        latest,
    };
}
