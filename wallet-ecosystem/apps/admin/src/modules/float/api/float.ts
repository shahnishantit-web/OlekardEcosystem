import { AdminAppClient } from '@/lib/api/admin-app-client';
import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
import type { AdminAgent } from '@/lib/api/admin-app-client';

export type { AdminAgent };

export function listFloat(
  client: AuthenticatedApiClient,
  params?: Record<string, string>,
): ReturnType<AdminAppClient['listFloat']> {
  return new AdminAppClient(client).listFloat(params);
}

export function adjustFloat(
  client: AuthenticatedApiClient,
  agentId: string,
  direction: 'credit' | 'debit',
  amountMinorUnits: number,
  reason: string,
  idempotencyKey: string,
): ReturnType<AdminAppClient['adjustFloat']> {
  return new AdminAppClient(client).adjustFloat(agentId, direction, amountMinorUnits, reason, idempotencyKey);
}
