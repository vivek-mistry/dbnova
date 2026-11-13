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
