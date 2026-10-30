import type { Cell } from '@/types';

/**
 * Encode a row's key values the way the server encodes page cursors:
 * base64 of a UTF-8 JSON list. Returns null when a key cell is not a plain
 * value (e.g. a truncated or binary key), since the row cannot be located.
 */
export function encodeRowKey(row: Cell[], keyIndexes: number[]): string | null {
    if (keyIndexes.length === 0) {
        return null;
    }

    const values = keyIndexes.map((index) => row[index]);

    if (values.some((value) => value !== null && typeof value === 'object')) {
        return null;
    }

    const bytes = new TextEncoder().encode(JSON.stringify(values));
    let binary = '';

    bytes.forEach((byte) => {
        binary += String.fromCharCode(byte);
    });

    return btoa(binary);
}
