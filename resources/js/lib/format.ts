const byteUnits = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

export function formatBytes(bytes: number | null): string {
    if (bytes === null) {
        return '—';
    }

    let value = bytes;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < byteUnits.length - 1) {
        value /= 1024;
        unitIndex++;
    }

    return `${value.toFixed(unitIndex === 0 ? 0 : 1)} ${byteUnits[unitIndex]}`;
}

export function formatNumber(value: number | null): string {
    return value === null ? '—' : value.toLocaleString();
}

export function formatDuration(totalSeconds: number): string {
    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);

    if (days > 0) {
        return `${days}d ${hours}h`;
    }

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }

    return `${minutes}m`;
}
