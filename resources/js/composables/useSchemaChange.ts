import { useHttp } from '@inertiajs/vue3';
import { ref } from 'vue';
import { httpErrorMessage } from '@/lib/httpError';
import { apply as applyRoute, preview as previewRoute } from '@/routes/schema';
import type { ApplyResult, DdlPlan, SchemaChangePayload } from '@/types';

/**
 * Preview a schema change as SQL, then apply it. A 409 from apply means MySQL
 * can only make the change by copying a big table; the caller asks the user
 * and retries with allowLocking.
 */
export function useSchemaChange(database: string) {
    const plan = ref<DdlPlan | null>(null);
    const error = ref<string | null>(null);
    const needsLockingConfirmation = ref(false);

    // Change payloads are nested JSON, so they are sent through transform()
    // rather than as typed form fields.
    const previewHttp = useHttp<Record<string, never>, DdlPlan>();
    const applyHttp = useHttp<Record<string, never>, ApplyResult>();

    function reset(): void {
        plan.value = null;
        error.value = null;
        needsLockingConfirmation.value = false;
    }

    async function preview(
        table: string,
        change: SchemaChangePayload,
    ): Promise<boolean> {
        reset();
        previewHttp.transform(() => ({ table, change }));

        try {
            plan.value = await previewHttp.post(previewRoute.url(database));

            return true;
        } catch (exception) {
            error.value = httpErrorMessage(
                exception,
                'Could not build the SQL.',
            );

            return false;
        }
    }

    async function apply(
        table: string,
        change: SchemaChangePayload,
        options: {
            allowLocking?: boolean;
            confirmation?: string;
            password?: string;
        } = {},
    ): Promise<ApplyResult | null> {
        error.value = null;
        needsLockingConfirmation.value = false;
        applyHttp.transform(() => ({
            table,
            change,
            allowLocking: options.allowLocking ?? false,
            confirmation: options.confirmation ?? '',
            password: options.password ?? '',
        }));

        try {
            return await applyHttp.post(applyRoute.url(database));
        } catch (exception) {
            const status = (exception as { response?: { status?: number } })
                .response?.status;

            needsLockingConfirmation.value = status === 409;
            error.value = httpErrorMessage(
                exception,
                'The change was not applied.',
            );

            return null;
        }
    }

    return {
        plan,
        error,
        needsLockingConfirmation,
        previewing: () => previewHttp.processing,
        applying: () => applyHttp.processing,
        preview,
        apply,
        reset,
    };
}
