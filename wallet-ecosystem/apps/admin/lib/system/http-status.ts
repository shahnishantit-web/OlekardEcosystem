export type HttpSystemStatus = 429 | 503 | 504;
export interface SystemFailure {
    status: HttpSystemStatus;
    correlationId?: string;
    retryAfterSeconds?: number;
}
export const SYSTEM_STATUS_EVENT = 'olekard:system-status';
export const STATUS_COPY = {
    '401': { title: 'Sign in required', message: 'Your session is unavailable or has expired. Sign in to continue.', action: 'Sign in' },
    '429': { title: 'Too many requests', message: 'The service is receiving too many requests. Wait a moment before trying again.', action: 'Try again' },
    '503': { title: 'Service temporarily unavailable', message: 'The service is temporarily unavailable. Please try again shortly.', action: 'Try again' },
    '504': { title: 'The service took too long', message: 'The server did not respond in time. Check the result of any recent operation before submitting it again.', action: 'Try again' },
    'offline': { title: 'You are offline', message: 'Check your internet connection, then reload this page. Verify any recent operation before submitting it again.', action: 'Try again' },
    'maintenance': { title: 'Scheduled maintenance', message: 'OleKard Admin is temporarily unavailable while maintenance is in progress.', action: 'Check availability' }
} as const;
export class SystemStatusError extends Error {
    readonly status: HttpSystemStatus;
    constructor(readonly detail: SystemFailure) { super(STATUS_COPY[String(detail.status) as '429' | '503' | '504'].message); this.name = 'SystemStatusError'; this.status = detail.status; }
}
export function throwForSystemStatus(response: Response): void {
    if (response.status !== 429 && response.status !== 503 && response.status !== 504)
        return;
    const detail: SystemFailure = { status: response.status };
    const correlation = response.headers.get('X-Correlation-ID');
    if (correlation !== null && /^[a-zA-Z0-9_-]{1,100}$/.test(correlation))
        detail.correlationId = correlation;
    const retry = response.headers.get('Retry-After');
    if (retry !== null) {
        const seconds = /^\d+$/.test(retry) ? Number(retry) : Math.ceil((Date.parse(retry) - Date.now()) / 1000);
        if (Number.isFinite(seconds) && seconds > 0 && seconds <= 86400)
            detail.retryAfterSeconds = seconds;
    }
    if (typeof window !== 'undefined')
        window.dispatchEvent(new CustomEvent<SystemFailure>(SYSTEM_STATUS_EVENT, { detail }));
    throw new SystemStatusError(detail);
}
