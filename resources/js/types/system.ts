export type SystemCheckStatus = 'ok' | 'warning' | 'failed';

export type SystemCheck = {
    name: string;
    status: SystemCheckStatus;
    detail: string;
    hint: string | null;
};
