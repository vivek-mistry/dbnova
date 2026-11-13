export type UploadStatus = 'uploading' | 'uploaded' | 'cancelled' | 'expired';

export type UploadSummary = {
    id: string;
    name: string;
    format: 'sql' | 'gz' | 'zip';
    size: number;
    chunkSize: number;
    totalChunks: number;
    status: UploadStatus;
    createdAt: string;
    received: number[];
};

export type ImportStatus =
    | 'pending'
    | 'preflight'
    | 'rejected'
    | 'ready'
    | 'queued'
    | 'importing'
    | 'completed'
    | 'failed'
    | 'cancelled'
    | 'interrupted';

export type PreflightReport = {
    accepted: boolean;
    problems: string[];
    warnings: string[];
    databases: string[];
    sourceDatabase: string | null;
    statementCount: number;
    tables: string[];
    tableCount: number;
    skippedStatements: number;
    storedObjectCount: number;
    disablesForeignKeyChecks: boolean;
    largestStatementBytes: number;
    sqlBytes: number;
    compoundProgramCount?: number;
};

export type ImportJobStatus = {
    id: string;
    database: string;
    status: ImportStatus;
    fileName: string | null;
    bytesTotal: number;
    bytesScanned: number;
    bytesStreamed: number;
    statementsSeen: number;
    errorCount: number;
    lastError: string | null;
    errorLine: number | null;
    preflight: PreflightReport | null;
    options: {
        speedMode?: boolean;
        skippedStatements?: number;
        warningCount?: number;
        warnings?: string[];
        targetReset?: boolean;
    };
    cancelRequested: boolean;
    startedAt: string | null;
    finishedAt: string | null;
    createdAt: string;
    conflicts?: string[];
};

export type ExportStatus =
    | 'queued'
    | 'exporting'
    | 'completed'
    | 'failed'
    | 'cancelled'
    | 'interrupted'
    | 'expired';

export type ExportFormat = 'sql' | 'sql.gz';

export type ExportContent = 'both' | 'structure' | 'data';

export type ExportJobStatus = {
    id: string;
    database: string;
    tables: string[] | null;
    format: ExportFormat;
    options: { content?: ExportContent; includePrograms?: boolean };
    status: ExportStatus;
    fileName: string;
    estimatedBytes: number;
    bytesWritten: number;
    fileBytes: number;
    lastError: string | null;
    cancelRequested: boolean;
    downloadUrl: string | null;
    startedAt: string | null;
    finishedAt: string | null;
    expiresAt: string | null;
    createdAt: string;
};

export type ExportDatabase = {
    name: string;
    sizeBytes: number;
    tableCount: number;
    isSystem: boolean;
};

export type ExportTable = {
    name: string;
    type: string;
    sizeBytes: number | null;
    approximateRows: number | null;
};
