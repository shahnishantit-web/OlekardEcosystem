import { AdminAppClient } from '@/lib/api/admin-app-client';
import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
import type { KycSubmission, PendingAction } from '@/lib/api/admin-app-client';

export type { KycSubmission, PendingAction };

export function listKycSubmissions(
  client: AuthenticatedApiClient,
  params?: Record<string, string>,
): ReturnType<AdminAppClient['listKyc']> {
  return new AdminAppClient(client).listKyc(params);
}

export function getKycSubmission(
  client: AuthenticatedApiClient,
  submissionId: string,
): ReturnType<AdminAppClient['getKyc']> {
  return new AdminAppClient(client).getKyc(submissionId);
}

export function approveKycSubmission(
  client: AuthenticatedApiClient,
  submissionId: string,
  idempotencyKey: string,
): ReturnType<AdminAppClient['approveKyc']> {
  return new AdminAppClient(client).approveKyc(submissionId, idempotencyKey);
}

export function rejectKycSubmission(
  client: AuthenticatedApiClient,
  submissionId: string,
  reason: string,
  idempotencyKey: string,
): ReturnType<AdminAppClient['rejectKyc']> {
  return new AdminAppClient(client).rejectKyc(submissionId, reason, idempotencyKey);
}
