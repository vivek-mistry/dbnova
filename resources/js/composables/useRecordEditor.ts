import { useHttp } from '@inertiajs/vue3';
import type { ShallowRef } from 'vue';
import { toast } from 'vue-sonner';
import { httpErrorMessage } from '@/lib/httpError';
import { encodeRowKey } from '@/lib/rowKey';
import {
    destroy as destroyRecords,
    store as storeRecord,
    update as updateRecord,
} from '@/routes/tables/records';
import type { Cell } from '@/types';

type Options = {
    database: string;
    table: string;
    rows: ShallowRef<Cell[][]>;
    keyIndexes: () => number[];
};

type ColumnValues = Record<string, string | null>;

/**
 * Saves edits to rows. Cell edits are applied immediately and rolled back
 * if MySQL rejects them, so the grid never waits for the server.
 */
export function useRecordEditor(options: Options) {
    const routeArgs = { database: options.database, table: options.table };

    const updateHttp = useHttp<
        { key: string; values: ColumnValues },
        { key: string; row: Cell[] | null }
    >({ key: '', values: {} });
    const insertHttp = useHttp<{ values: ColumnValues }, { message: string }>({
        values: {},
    });
    const deleteHttp = useHttp<{ keys: string[] }, { deleted: number }>({
        keys: [],
    });

    function replaceRow(rowIndex: number, row: Cell[] | null): void {
        options.rows.value =
            row === null
                ? options.rows.value.filter((_, index) => index !== rowIndex)
                : options.rows.value.map((current, index) =>
                      index === rowIndex ? row : current,
                  );
    }

    async function updateCell(
        rowIndex: number,
        columnIndex: number,
        columnName: string,
        value: string | null,
    ): Promise<boolean> {
        const original = options.rows.value[rowIndex];
        const key = original
            ? encodeRowKey(original, options.keyIndexes())
            : null;

        if (!original || key === null) {
            toast.error(
                'This row cannot be edited because its key is not a plain value.',
            );

            return false;
        }

        replaceRow(
            rowIndex,
            original.map((cell, index) =>
                index === columnIndex ? value : cell,
            ),
        );

        updateHttp.key = key;
        updateHttp.values = { [columnName]: value };

        try {
            const response = await updateHttp.patch(
                updateRecord.url(routeArgs),
            );
            replaceRow(rowIndex, response.row);

            return true;
        } catch (exception) {
            replaceRow(rowIndex, original);
            toast.error(
                httpErrorMessage(exception, 'The change was not saved.'),
            );

            return false;
        }
    }

    async function insertRow(values: ColumnValues): Promise<boolean> {
        insertHttp.values = values;

        try {
            await insertHttp.post(storeRecord.url(routeArgs));
            toast.success('Row inserted.');

            return true;
        } catch (exception) {
            toast.error(
                httpErrorMessage(exception, 'The row was not inserted.'),
            );

            return false;
        }
    }

    async function deleteRows(keys: string[]): Promise<boolean> {
        deleteHttp.keys = keys;

        try {
            const response = await deleteHttp.delete(
                destroyRecords.url(routeArgs),
            );
            const deletedKeys = new Set(keys);

            options.rows.value = options.rows.value.filter((row) => {
                const key = encodeRowKey(row, options.keyIndexes());

                return key === null || !deletedKeys.has(key);
            });
            toast.success(
                `Deleted ${response.deleted} row${response.deleted === 1 ? '' : 's'}.`,
            );

            return true;
        } catch (exception) {
            toast.error(
                httpErrorMessage(exception, 'The rows were not deleted.'),
            );

            return false;
        }
    }

    return {
        updateCell,
        insertRow,
        deleteRows,
        inserting: () => insertHttp.processing,
        deleting: () => deleteHttp.processing,
    };
}
