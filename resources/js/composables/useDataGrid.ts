import { useHttp } from '@inertiajs/vue3';
import { ref, shallowRef } from 'vue';
import { httpErrorMessage } from '@/lib/httpError';
import { count as countRoute, rows as rowsRoute } from '@/routes/tables';
import type { Cell, ColumnFilter, SortState, TablePage } from '@/types';

type Options = {
    database: string;
    table: string;
    pageSize: number;
    firstPage: TablePage;
};

/**
 * Server-side paging, sorting and filtering for one table. Rows are kept in
 * a shallowRef and replaced or appended, never deeply watched.
 */
export function useDataGrid(options: Options) {
    const rows = shallowRef<Cell[][]>(options.firstPage.rows);
    const lastPage = shallowRef<TablePage>(options.firstPage);
    const sort = ref<SortState>(null);
    const filters = ref<ColumnFilter[]>([]);
    const error = ref<string | null>(null);
    const loading = ref(false);
    const exactCount = ref<number | null>(null);

    const http = useHttp<Record<string, never>, TablePage>();
    const countHttp = useHttp<Record<string, never>, { count: number }>();

    const routeArgs = { database: options.database, table: options.table };

    function queryString(extra: Record<string, string | number>): string {
        const params = new URLSearchParams();

        for (const [key, value] of Object.entries(extra)) {
            params.set(key, String(value));
        }

        if (sort.value) {
            params.set('sort', sort.value.column);
            params.set('direction', sort.value.direction);
        }

        filters.value.forEach((filter, index) => {
            params.set(`filters[${index}][column]`, filter.column);
            params.set(`filters[${index}][operator]`, filter.operator);
            params.set(`filters[${index}][value]`, filter.value);
        });

        return params.toString();
    }

    async function fetchPage(
        position: Record<string, string | number>,
    ): Promise<TablePage | null> {
        loading.value = true;
        error.value = null;

        try {
            return await http.get(
                `${rowsRoute.url(routeArgs)}?${queryString({ limit: options.pageSize, ...position })}`,
            );
        } catch (exception) {
            error.value = httpErrorMessage(exception, 'Could not load rows.');

            return null;
        } finally {
            loading.value = false;
        }
    }

    /** Fetch the next page and append it. */
    async function loadMore(): Promise<void> {
        const page = lastPage.value;

        if (loading.value || !page.hasMore) {
            return;
        }

        const next = await fetchPage(
            page.nextCursor !== null
                ? { cursor: page.nextCursor }
                : { offset: page.nextOffset ?? 0 },
        );

        if (next) {
            rows.value = [...rows.value, ...next.rows];
            lastPage.value = next;
        }
    }

    /** Start again from the first page, e.g. after the sort or filters change. */
    async function reload(): Promise<void> {
        http.cancel();
        exactCount.value = null;

        const first = await fetchPage({});

        if (first) {
            rows.value = first.rows;
            lastPage.value = first;
        }
    }

    function toggleSort(column: string): void {
        if (sort.value?.column !== column) {
            sort.value = { column, direction: 'asc' };
        } else if (sort.value.direction === 'asc') {
            sort.value = { column, direction: 'desc' };
        } else {
            sort.value = null;
        }

        void reload();
    }

    function applyFilters(next: ColumnFilter[]): void {
        filters.value = next;
        void reload();
    }

    async function countRows(): Promise<void> {
        try {
            const result = await countHttp.get(
                `${countRoute.url(routeArgs)}?${queryString({})}`,
            );
            exactCount.value = result.count;
        } catch (exception) {
            error.value = httpErrorMessage(exception, 'Could not count rows.');
        }
    }

    return {
        rows,
        lastPage,
        sort,
        filters,
        error,
        loading,
        exactCount,
        counting: () => countHttp.processing,
        loadMore,
        reload,
        toggleSort,
        applyFilters,
        countRows,
    };
}
