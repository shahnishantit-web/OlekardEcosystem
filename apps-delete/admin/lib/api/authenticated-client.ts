import { generateCorrelationId } from '../utils/correlation';
import { isApiError } from '../utils/errors';
import type { ApiError } from '../utils/errors';
import type { ApiResponse } from './client';

interface RequestOptions {
  idempotencyKey?: string;
  signal?: AbortSignal;
}

export class AuthenticatedApiClient {
  private readonly baseUrl: string;
  private readonly accessToken: string;

  constructor(accessToken: string, baseUrl?: string) {
    this.baseUrl = (baseUrl ?? process.env['NEXT_PUBLIC_API_URL'] ?? '/api').replace(/\/$/, '');
    this.accessToken = accessToken;
  }

  private buildHeaders(options: RequestOptions = {}): Headers {
    const headers = new Headers({
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-Correlation-ID': generateCorrelationId(),
      Authorization: `Bearer ${this.accessToken}`,
    });
    if (options.idempotencyKey) {
      headers.set('Idempotency-Key', options.idempotencyKey);
    }
    return headers;
  }

  private async request<T>(
    method: string,
    path: string,
    body?: unknown,
    options: RequestOptions = {},
  ): Promise<ApiResponse<T>> {
    const url = `${this.baseUrl}${path}`;
    const response = await fetch(url, {
      method,
      headers: this.buildHeaders(options),
      ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
      ...(options.signal !== undefined ? { signal: options.signal } : {}),
    });

    const json: ApiResponse<T> | ApiError = await response.json() as ApiResponse<T> | ApiError;

    if (!response.ok || isApiError(json)) {
      throw json;
    }

    return json as ApiResponse<T>;
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

  async delete(path: string, options?: RequestOptions): Promise<void> {
    await this.request<null>('DELETE', path, undefined, options);
  }
}
