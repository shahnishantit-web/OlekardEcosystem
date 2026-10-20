import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';

export interface AdminPortalDashboard {
  total_customers: number;
  active_wallets: number;
  total_transaction_volume_today: number;
  failed_transactions_today: number;
  pending_kyc_cases: number;
  active_agents: number;
  open_risk_alerts: number;
  active_account_holds: number;
  currency: string;
  period: string;
  cache_age_seconds: number;
}

export interface AdminUserProfile {
  id: string;
  email: string;
  status: string;
  kyc_tier: number | null;
  created_at: string;
  profile?: {
    first_name: string | null;
    last_name: string | null;
    phone_number: string | null;
  };
  kyc?: {
    tier: number;
    status: string;
  };
}

export interface AdminWalletDetail {
  id: string;
  owner_id: string;
  owner_email: string | null;
  status: string;
  currency: string;
  created_at: string;
  pockets: Array<{
    currency_code: string;
    available_balance: string;
    ledger_account_reference: string;
  }>;
  account_hold: {
    id: string;
    hold_type: string;
    placed_by: string;
    placed_at: string;
  } | null;
}

export interface AdminTransaction {
  id: string;
  reference: string;
  type: string;
  status: string;
  amount: number;
  currency_code: string;
  direction: string;
  wallet_id: string;
  customer_id: string;
  customer_email: string | null;
  source_module: string;
  created_at: string;
  completed_at: string | null;
  failed_at: string | null;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
}

export async function getAdminPortalDashboard(
  client: AuthenticatedApiClient,
  period = 'today',
): Promise<AdminPortalDashboard> {
  const res = await client.get<AdminPortalDashboard>(`/v1/admin/dashboard?period=${period}`);
  return res.data;
}

export async function searchAdminUsers(
  client: AuthenticatedApiClient,
  params: {
    q?: string;
    status?: string;
    kyc_status?: string;
    date_from?: string;
    date_to?: string;
    page?: number;
    per_page?: number;
  },
): Promise<PaginatedResponse<AdminUserProfile>> {
  const query = new URLSearchParams();
  if (params.q) query.set('q', params.q);
  if (params.status) query.set('status', params.status);
  if (params.kyc_status) query.set('kyc_status', params.kyc_status);
  if (params.date_from) query.set('date_from', params.date_from);
  if (params.date_to) query.set('date_to', params.date_to);
  if (params.page) query.set('page', String(params.page));
  if (params.per_page) query.set('per_page', String(params.per_page));

  const res = await client.get<PaginatedResponse<AdminUserProfile> | AdminUserProfile[]>(`/v1/admin/customers?${query}`);
  if (Array.isArray(res.data)) {
    return {
      data: res.data,
      meta: (res.meta ?? { current_page: 1, per_page: res.data.length, total: res.data.length, last_page: 1 }) as PaginatedResponse<AdminUserProfile>['meta'],
    };
  }
  return res.data;
}

export async function exportAdminUsers(
  client: AuthenticatedApiClient,
  params: { q?: string; status?: string; kyc_status?: string; format?: string },
): Promise<{ export_id: string; status: string }> {
  const res = await client.post<{ export_id: string; status: string }>(
    '/v1/admin/users/export',
    { format: 'csv', ...params },
  );
  return res.data;
}

export async function getAdminUserProfile(
  client: AuthenticatedApiClient,
  userId: string,
): Promise<AdminUserProfile> {
  const res = await client.get<AdminUserProfile>(`/v1/admin/customers/${userId}`);
  return res.data;
}

export async function searchAdminWallets(
  client: AuthenticatedApiClient,
  params: { q?: string; status?: string; page?: number; per_page?: number },
): Promise<PaginatedResponse<AdminWalletDetail>> {
  const query = new URLSearchParams();
  if (params.q) query.set('q', params.q);
  if (params.status) query.set('status', params.status);
  if (params.page) query.set('page', String(params.page));
  if (params.per_page) query.set('per_page', String(params.per_page));

  const res = await client.get<PaginatedResponse<AdminWalletDetail>>(`/v1/admin/wallets?${query}`);
  return res.data;
}

export async function searchAdminTransactions(
  client: AuthenticatedApiClient,
  params: { q?: string; type?: string; status?: string; from?: string; to?: string; page?: number; per_page?: number },
): Promise<PaginatedResponse<AdminTransaction>> {
  const query = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v != null) query.set(k, String(v));
  });

  const res = await client.get<PaginatedResponse<AdminTransaction>>(`/v1/admin/transactions?${query}`);
  return res.data;
}

export async function exportAdminTransactions(
  client: AuthenticatedApiClient,
  params: { format?: string; type?: string; status?: string; from?: string; to?: string },
): Promise<{ export_id: string; status: string }> {
  const res = await client.post<{ export_id: string; status: string }>('/v1/admin/transactions/export', params);
  return res.data;
}

export async function getExportStatus(
  client: AuthenticatedApiClient,
  exportId: string,
): Promise<{ export_id: string; status: string; download_url: string | null; row_count?: number }> {
  const res = await client.get<{ export_id: string; status: string; download_url: string | null; row_count?: number }>(`/v1/admin/transactions/exports/${exportId}`);
  return res.data;
}
