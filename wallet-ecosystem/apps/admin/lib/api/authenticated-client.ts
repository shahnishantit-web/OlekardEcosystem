import { throwForSystemStatus } from '@/lib/system/http-status';
import { generateCorrelationId } from '@/lib/utils/correlation';
import { isApiError } from '@/lib/utils/errors';
import type { ApiError } from '@/lib/utils/errors';
import type { ApiResponse } from '@/lib/api/client';
interface RequestOptions {
    idempotencyKey?: string;
    signal?: AbortSignal;
    /** Explicit domain failures rendered by the caller; other 503s remain global. */
    domainErrorCodes?: readonly string[];
}
/** Called when the API returns 401 so the auth context can sign the user out. */
type OnUnauthorized = () => void;
export class AuthenticatedApiClient {
    private readonly baseUrl: string;
    private readonly accessToken: string;
    private readonly onUnauthorized: OnUnauthorized | undefined;
    constructor(accessToken: string, baseUrl?: string, onUnauthorized?: OnUnauthorized) {
        this.baseUrl = (baseUrl ?? process.env['NEXT_PUBLIC_API_URL'] ?? '/api').replace(/\/$/, '');
        this.accessToken = accessToken;
        this.onUnauthorized = onUnauthorized;
    }
    private buildHeaders(options: RequestOptions = {}): Headers {
        const headers = new Headers({
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Correlation-ID': generateCorrelationId(),
            Authorization: `Bearer ${this.accessToken}`,
        });
        if (options.idempotencyKey !== undefined && options.idempotencyKey !== '') {
            headers.set('Idempotency-Key', options.idempotencyKey);
        }
        return headers;
    }
    private async request<T>(method: string, path: string, body?: unknown, options: RequestOptions = {}): Promise<ApiResponse<T>> {
        const url = `${this.baseUrl}${path}`;
        const response = await fetch(url, {
            method,
            headers: this.buildHeaders(options),
            ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
            ...(options.signal !== undefined ? { signal: options.signal } : {}),
        });
        // Trigger sign-out before parsing the body so the auth state is cleared
        // as quickly as possible. The throw below propagates the error to the caller.
        if (response.status === 401) {
            this.onUnauthorized?.();
        }
        if (response.status === 503 && options.domainErrorCodes !== undefined && options.domainErrorCodes.length > 0) {
            // Clone keeps the original body available for normal error processing.
            const domain: unknown = await response.clone().json().catch(() => null);
            if (isApiError(domain) && typeof domain.error?.code === 'string' && options.domainErrorCodes.includes(domain.error.code))
                throw domain;
        }
        throwForSystemStatus(response);
        const json: ApiResponse<T> | ApiError = await response.json() as ApiResponse<T> | ApiError;
        if (!response.ok || isApiError(json)) {
            throw json;
        }
        return json as ApiResponse<T>;
    }
    async getBlob(path: string): Promise<Blob> {
        const response = await fetch(this.baseUrl + path, { headers: this.buildHeaders() });
        if (response.status === 401)
            this.onUnauthorized?.();
        throwForSystemStatus(response);
        if (!response.ok)
            throw await response.json() as unknown;
        return response.blob();
    }
    async get<T>(path: string, options?: RequestOptions): Promise<ApiResponse<T>> {
        return this.request<T>('GET', path, undefined, options);
    }
    async post<T>(path: string, body: unknown, options?: RequestOptions): Promise<ApiResponse<T>> {
        return this.request<T>('POST', path, body, options);
    }
    async put<T>(path: string, body: unknown, options?: RequestOptions): Promise<ApiResponse<T>> {
        return this.request<T>('PUT', path, body, options);
    }
    async patch<T>(path: string, body: unknown, options?: RequestOptions): Promise<ApiResponse<T>> {
        return this.request<T>('PATCH', path, body, options);
    }
    async delete(path: string, options?: RequestOptions): Promise<void> {
        await this.request<null>('DELETE', path, undefined, options);
    }
}
