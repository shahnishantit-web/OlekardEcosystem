import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';

export interface WalletsSummary {
  active_wallets: number;
}

export interface TransactionsTodaySummary {
  transactions_today: number;
  total_value_today_minor_units: number;
}

export interface KycPendingSummary {
  pending_reviews: number;
}

export interface AgentsSummary {
  active_agents: number;
}

export interface DisputesSummary {
  open_disputes: number;
}

export async function getActiveWalletsSummary(
  client: AuthenticatedApiClient,
): Promise<WalletsSummary> {
  const res = await client.get<{ active: number }>('/v1/admin/dashboard/wallets');
  return { active_wallets: res.data.active };
}

export async function getTransactionsTodaySummary(
  client: AuthenticatedApiClient,
): Promise<TransactionsTodaySummary> {
  const res = await client.get<{ total: number; volume: number }>('/v1/admin/dashboard/transactions?period=today');
  return { transactions_today: res.data.total, total_value_today_minor_units: Math.round(res.data.volume * 100) };
}

export async function getKycPendingSummary(
  client: AuthenticatedApiClient,
): Promise<KycPendingSummary> {
  const res = await client.get<{ pending: number }>('/v1/admin/dashboard/kyc');
  return { pending_reviews: res.data.pending };
}

export async function getActiveAgentsSummary(
  client: AuthenticatedApiClient,
): Promise<AgentsSummary> {
  const res = await client.get<{ active: number }>('/v1/admin/dashboard/agents?period=today');
  return { active_agents: res.data.active };
}

export async function getOpenDisputesSummary(
  client: AuthenticatedApiClient,
): Promise<DisputesSummary> {
  const res = await client.get<DisputesSummary>('/v1/admin/support/summary');
  return res.data;
}
