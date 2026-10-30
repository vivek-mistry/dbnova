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
