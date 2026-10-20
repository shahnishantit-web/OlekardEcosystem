import { AdminAppClient } from '@/lib/api/admin-app-client';
import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
import type { AdminAgent, PendingAction } from '@/lib/api/admin-app-client';

export type { AdminAgent, PendingAction };

export function listAgents(
  client: AuthenticatedApiClient,
  params?: Record<string, string>,
): ReturnType<AdminAppClient['listAgents']> {
  return new AdminAppClient(client).listAgents(params);
}

export function getAgent(
  client: AuthenticatedApiClient,
  agentId: string,
): ReturnType<AdminAppClient['getAgent']> {
  return new AdminAppClient(client).getAgent(agentId);
}

export function approveAgent(
  client: AuthenticatedApiClient,
  agentId: string,
  idempotencyKey: string,
): ReturnType<AdminAppClient['approveAgent']> {
  return new AdminAppClient(client).approveAgent(agentId, idempotencyKey);
}

export function updateAgentStatus(
  client: AuthenticatedApiClient,
  agentId: string,
  action: 'suspend' | 'reactivate',
  reason: string,
  idempotencyKey: string,
): ReturnType<AdminAppClient['updateAgentStatus']> {
  return new AdminAppClient(client).updateAgentStatus(agentId, action, reason, idempotencyKey);
}
