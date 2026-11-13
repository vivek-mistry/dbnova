import type { TableInfo } from './database';

export type ColumnInfo = {
    name: string;
    dataType: string;
    columnType: string;
    nullable: boolean;
    key: string;
    default: string | null;
    extra: string;
    comment: string;
    collation: string | null;
    isBinary: boolean;
};

export type IndexInfo = {
    name: string;
    columns: string[];
    unique: boolean;
    type: string;
};

export type BinaryCell = { binary: number };
export type TruncatedCell = { preview: string; truncated: true };
export type Cell = string | number | null | BinaryCell | TruncatedCell;

export type TablePage = {
    rows: Cell[][];
    hasMore: boolean;
    strategy: 'keyset' | 'offset';
    nextCursor: string | null;
    nextOffset: number | null;
    durationMs: number;
};

export type FilterOperator =
    | '='
    | '!='
    | 'like'
    | 'not_like'
    | 'is_null'
    | 'is_not_null'
    | '>'
    | '>='
    | '<'
    | '<=';

export type ColumnFilter = {
    column: string;
    operator: FilterOperator;
    value: string;
};

export type SortState = {
    column: string;
    direction: 'asc' | 'desc';
} | null;

export type CellValue = { text: string } | { bytes: number; hex: string };

export type TablePageProps = {
    database: string;
    table: TableInfo;
};
