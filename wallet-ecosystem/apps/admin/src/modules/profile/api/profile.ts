import { generateCorrelationId } from '@/lib/utils/correlation';
import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';

export interface AdminProfileAddress {
  street: string | null;
  city: string | null;
  region: string | null;
  postal_code: string | null;
  country: string | null;
}

export interface AdminProfileData {
  user_id: string;
  first_name: string | null;
  last_name: string | null;
  date_of_birth: string | null;
  gender: string | null;
  address: AdminProfileAddress;
  preferred_language: string | null;
  email: string | null;
  mobile_number: string | null;
  profile_photo_url: string | null;
  account_status: string;
  created_at: string;
  updated_at: string;
}

export interface AdminStatusHistoryEntry {
  from_status: string | null;
  to_status: string;
  reason: string | null;
  actor_type: string;
  actor_id: string | null;
  created_at: string;
}

export interface AdminAccountStatusData {
  account_status: string;
  history: AdminStatusHistoryEntry[];
}

export interface UpdatePersonalInfoPayload {
  first_name?: string;
  last_name?: string;
  date_of_birth?: string;
  gender?: string;
  address?: Partial<AdminProfileAddress>;
  preferred_language?: string;
}

export async function getAdminProfile(
  client: AuthenticatedApiClient,
  userId: string,
): Promise<AdminProfileData> {
  const res = await client.get<AdminProfileData>(`/v1/admin/users/${userId}/profile`);
  return res.data;
}

export async function updateAdminProfilePersonal(
  client: AuthenticatedApiClient,
  userId: string,
  payload: UpdatePersonalInfoPayload,
): Promise<AdminProfileData> {
  const res = await client.put<AdminProfileData>(
    `/v1/admin/users/${userId}/profile/personal`,
    payload,
    { idempotencyKey: generateCorrelationId() },
  );
  return res.data;
}

export async function getAdminAccountStatus(
  client: AuthenticatedApiClient,
  userId: string,
): Promise<AdminAccountStatusData> {
  const res = await client.get<AdminAccountStatusData>(`/v1/admin/users/${userId}/profile/status`);
  return res.data;
}

export async function suspendAccount(
  client: AuthenticatedApiClient,
  userId: string,
  reason: string,
): Promise<{ account_status: string }> {
  const res = await client.post<{ account_status: string }>(
    `/v1/admin/users/${userId}/suspend`,
    { reason },
    { idempotencyKey: generateCorrelationId() },
  );
  return res.data;
}

export async function reactivateAccount(
  client: AuthenticatedApiClient,
  userId: string,
  reason?: string,
): Promise<{ account_status: string }> {
  const res = await client.post<{ account_status: string }>(
    `/v1/admin/users/${userId}/reactivate`,
    reason ? { reason } : {},
    { idempotencyKey: generateCorrelationId() },
  );
  return res.data;
}

export async function cancelClosureAccount(
  client: AuthenticatedApiClient,
  userId: string,
  reason?: string,
): Promise<{ account_status: string }> {
  const res = await client.post<{ account_status: string }>(
    `/v1/admin/users/${userId}/cancel-closure`,
    reason ? { reason } : {},
    { idempotencyKey: generateCorrelationId() },
  );
  return res.data;
}

export async function closeAccount(
  client: AuthenticatedApiClient,
  userId: string,
  reason?: string,
): Promise<{ account_status: string }> {
  const res = await client.post<{ account_status: string }>(
    `/v1/admin/users/${userId}/close`,
    reason ? { reason } : {},
    { idempotencyKey: generateCorrelationId() },
  );
  return res.data;
}
