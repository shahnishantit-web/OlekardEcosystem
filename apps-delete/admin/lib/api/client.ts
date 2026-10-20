import { generateCorrelationId } from '../utils/correlation';
import { isApiError, type ApiError } from '../utils/errors';

export interface ApiResponse<T> {
  success: true;
  data: T;
  error: null;
  correlation_id: string;
  meta: Record<string, unknown> | null;
}

export type ApiResult<T> = ApiResponse<T> | ApiError;

interface RequestOptions {
  idempotencyKey?: string;
  signal?: AbortSignal;
}

export class ApiClient {
  private readonly baseUrl: string;

  constructor(baseUrl: string = process.env.NEXT_PUBLIC_API_URL ?? '/api') {
    this.baseUrl = baseUrl.replace(/\/$/, '');
  }

  private buildHeaders(options: RequestOptions = {}): Headers {
    const headers = new Headers({
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-Correlation-ID': generateCorrelationId(),
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

    const json: ApiResult<T> = await response.json();

    if (!response.ok || isApiError(json)) {
      throw json;
    }

    return json as ApiResponse<T>;
  }

  async get<T>(path: string, options?: RequestOptions): Promise<ApiResponse<T>> {
    return this.request<T>('GET', path, undefined, options);
  }

  async post<T>(
    path: string,
    body: unknown,
    options?: RequestOptions,
  ): Promise<ApiResponse<T>> {
    return this.request<T>('POST', path, body, options);
  }

  async patch<T>(
    path: string,
    body: unknown,
    options?: RequestOptions,
  ): Promise<ApiResponse<T>> {
    return this.request<T>('PATCH', path, body, options);
  }

  async delete(path: string, options?: RequestOptions): Promise<void> {
    await this.request<null>('DELETE', path, undefined, options);
  }
}

export const apiClient = new ApiClient();
