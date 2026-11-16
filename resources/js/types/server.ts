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
