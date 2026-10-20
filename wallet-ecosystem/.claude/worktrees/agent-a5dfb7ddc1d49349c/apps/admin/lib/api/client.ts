import { generateCorrelationId } from '@/lib/utils/correlation';

export interface ApiResponse<T> {
  success: boolean;
  data: T | null;
  error: {
    code: string;
    message: string;
    details?: Record<string, unknown>;
  } | null;
  correlation_id: string;
  meta: Record<string, unknown> | null;
}

export class ApiClient {
  private readonly baseUrl: string;

  constructor(baseUrl: string = process.env.NEXT_PUBLIC_API_URL ?? '') {
    this.baseUrl = baseUrl;
  }

  async get<T>(path: string, options: RequestInit = {}): Promise<ApiResponse<T>> {
    return this.request<T>('GET', path, undefined, options);
  }

  async post<T>(
    path: string,
    body: unknown,
    options: RequestInit & { idempotencyKey?: string } = {},
  ): Promise<ApiResponse<T>> {
    const { idempotencyKey, ...rest } = options;
    const extraHeaders: Record<string, string> = {};
    if (idempotencyKey) {
      extraHeaders['Idempotency-Key'] = idempotencyKey;
    }
    return this.request<T>('POST', path, body, { ...rest, headers: { ...rest.headers as Record<string, string>, ...extraHeaders } });
  }

  async patch<T>(path: string, body: unknown, options: RequestInit = {}): Promise<ApiResponse<T>> {
    return this.request<T>('PATCH', path, body, options);
  }

  async delete(path: string, options: RequestInit = {}): Promise<ApiResponse<null>> {
    return this.request<null>('DELETE', path, undefined, options);
  }

  private async request<T>(
    method: string,
    path: string,
    body: unknown,
    options: RequestInit,
  ): Promise<ApiResponse<T>> {
    const correlationId = generateCorrelationId();

    const response = await fetch(`${this.baseUrl}${path}`, {
      method,
      headers: {
        'Content-Type': 'application/json',
        'X-Correlation-ID': correlationId,
        ...(options.headers as Record<string, string>),
      },
      body: body !== undefined ? JSON.stringify(body) : undefined,
      ...options,
    });

    return response.json() as Promise<ApiResponse<T>>;
  }
}
