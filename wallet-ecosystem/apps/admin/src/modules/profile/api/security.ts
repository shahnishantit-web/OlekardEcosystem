import { generateCorrelationId } from '@/lib/utils/correlation';
import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';

export interface ChangePasswordPayload {
  current_password: string;
  new_password: string;
  new_password_confirmation: string;
}

export async function changePassword(
  client: AuthenticatedApiClient,
  payload: ChangePasswordPayload,
): Promise<void> {
  await client.post<{ message: string }>(
    '/v1/admin/auth/change-password',
    payload,
    { idempotencyKey: generateCorrelationId() },
  );
}

export interface MfaMethodsResponse {
  methods: string[];
  enrolled: boolean;
}

export async function listMfaMethods(
  client: AuthenticatedApiClient,
): Promise<MfaMethodsResponse> {
  const res = await client.get<MfaMethodsResponse>('/v1/auth/mfa/methods');
  return res.data;
}

export async function disableMfaMethod(
  client: AuthenticatedApiClient,
  method: 'totp' | 'fido2',
): Promise<void> {
  await client.post<{ success: true }>(
    '/v1/auth/mfa/disable',
    { method },
    { idempotencyKey: generateCorrelationId() },
  );
}
