import type { Cell } from './table';

export type ColumnDefaultKind = 'none' | 'null' | 'value' | 'current_timestamp';

/** A column as edited in the schema forms. */
export type ColumnForm = {
    name: string;
    type: string;
    length: string;
    valuesText: string;
    unsigned: boolean;
    nullable: boolean;
    defaultKind: ColumnDefaultKind;
    defaultValue: string;
    autoIncrement: boolean;
    onUpdateCurrentTimestamp: boolean;
    collation: string;
    comment: string;
};

/** A column as sent to the server. */
export type ColumnPayload = {
    name: string;
    type: string;
    length: string | null;
    values: string[];
    unsigned: boolean;
    nullable: boolean;
    default: { kind: ColumnDefaultKind; value: string | null };
    autoIncrement: boolean;
    onUpdateCurrentTimestamp: boolean;
    collation: string | null;
    comment: string;
};

export type SchemaChangePayload = { type: string } & Record<string, unknown>;

export type DdlPlan = {
    statements: string[];
    warnings: string[];
    approximateRows: number | null;
};

export type ForeignKeyInfo = {
    name: string;
    columns: string[];
    referencedDatabase: string;
    referencedTable: string;
    referencedColumns: string[];
    onUpdate: string;
    onDelete: string;
};

export type ApplyResult = {
    message: string;
    algorithm: 'INSTANT' | 'INPLACE' | 'COPY' | null;
    redirect: string | null;
};

export type StatementResult = {
    line: number;
    sql: string;
    keyword: string;
    status: 'ok' | 'error' | 'skipped';
    columns: string[] | null;
    rows: Cell[][] | null;
    rowsTruncated: boolean;
    affectedRows: number | null;
    lastInsertId: string | null;
    warnings: { level: string; code: number; message: string }[];
    error: string | null;
    errorCode: number | null;
    durationMs: number;
};

export type QueryHistoryItem = {
    id: number;
    database: string | null;
    sql: string;
    statementCount: number;
    status: 'ok' | 'error';
    error: string | null;
    durationMs: number;
    name: string | null;
    saved: boolean;
    createdAt: string;
};

export type QueryRunResponse = {
    statements: StatementResult[];
    succeeded: boolean;
    durationMs: number;
    history: QueryHistoryItem;
};

export type DestructiveStatement = {
    line: number;
    keyword: string;
    sql: string;
};
