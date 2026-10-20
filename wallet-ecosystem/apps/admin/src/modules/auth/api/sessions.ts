import { generateCorrelationId } from '@/lib/utils/correlation';
import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';

export interface AdminSessionItem {
  session_id: string;
  user_id: string;
  user_email?: string;
  device_name?: string;
  platform?: string;
  actor_type: string;
  status: string;
  last_activity_at: string;
  expires_at: string;
  is_current: boolean;
}

export interface AdminSessionsPagination {
  page: number;
  per_page: number;
  total: number;
}

export interface AdminSessionsResponse {
  sessions: AdminSessionItem[];
  pagination: AdminSessionsPagination;
}

export interface ListSessionsParams {
  search?: string;
  page?: number;
}

export async function listAdminSessions(
  client: AuthenticatedApiClient,
  params?: ListSessionsParams,
): Promise<AdminSessionsResponse> {
  const query = new URLSearchParams();
  if (params?.search) query.set('search', params.search);
  if (params?.page !== undefined) query.set('page', String(params.page));
  const qs = query.toString();
  const res = await client.get<AdminSessionsResponse>(
    `/v1/admin/sessions${qs ? `?${qs}` : ''}`,
  );
  return res.data;
}

export async function revokeAdminSession(
  client: AuthenticatedApiClient,
  sessionId: string,
): Promise<void> {
  await client.post<{ success: true }>(
    `/v1/admin/sessions/${sessionId}/revoke`,
    {},
    { idempotencyKey: `revoke-${sessionId}-${generateCorrelationId()}` },
  );
}

export interface OwnSessionItem {
  id: string;
  device_id: string | null;
  actor_type: string;
  status: string;
  last_activity_at: string;
  expires_at: string;
  created_at: string;
}

export async function listOwnSessions(
  client: AuthenticatedApiClient,
): Promise<OwnSessionItem[]> {
  const res = await client.get<OwnSessionItem[]>('/v1/auth/sessions');
  return Array.isArray(res.data) ? res.data : [];
}

export async function revokeOwnSession(
  client: AuthenticatedApiClient,
  sessionId: string,
): Promise<void> {
  await client.delete(`/v1/auth/sessions/${sessionId}`);
}
