export type ServerInfo = {
    version: string;
    uptimeSeconds: number;
    databaseCount: number;
    totalSizeBytes: number;
};

export type DatabaseInfo = {
    name: string;
    characterSet: string;
    collation: string;
    tableCount: number;
    sizeBytes: number;
    isSystem: boolean;
};

export type TableInfo = {
    name: string;
    type: 'table' | 'view';
    engine: string | null;
    approximateRows: number | null;
    sizeBytes: number | null;
    collation: string | null;
    /** Rows (for InnoDB, the clustered primary key). */
    dataBytes: number | null;
    /** Secondary indexes. */
    indexBytes: number | null;
    /** Allocated but unused space (DATA_FREE); InnoDB keeps a few MB free per table. */
    freeBytes: number | null;
};

export type MaintenanceOperation = 'optimize' | 'analyze' | 'check' | 'repair';

export type MaintenanceResult = {
    table: string;
    operation: string;
    type: string;
    message: string;
};

export type CollationInfo = {
    name: string;
    characterSet: string;
    isDefault: boolean;
};

export type DatabaseTreeNode = {
    name: string;
    isSystem: boolean;
};

export type DatabaseTreeTable = {
    name: string;
    type: 'table' | 'view';
};
