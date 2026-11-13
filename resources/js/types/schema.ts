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
