export type ProcessRow = {
    id: number;
    user: string;
    host: string | null;
    database: string | null;
    command: string;
    timeSeconds: number;
    state: string | null;
    info: string | null;
    isCurrentConnection: boolean;
};

export type ProcessList = {
    processes: ProcessRow[];
    error: string | null;
};

export type NameValueRow = { name: string; value: string };

export type ServerMetrics = {
    takenAt: number;
    uptimeSeconds: number;
    questions: number;
    threadsConnected: number;
    threadsRunning: number;
    maxConnections: number;
    bufferPoolPagesTotal: number;
    bufferPoolPagesFree: number;
    bytesReceived: number;
    bytesSent: number;
};

export type AuditEntry = {
    id: number;
    createdAt: string;
    user: string | null;
    action: string;
    database: string | null;
    table: string | null;
    details: Record<string, unknown> | null;
    succeeded: boolean;
    error: string | null;
    ipAddress: string | null;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type PreferenceField = {
    value: number;
    default: number;
    min: number;
    max: number;
    isDefault: boolean;
};
