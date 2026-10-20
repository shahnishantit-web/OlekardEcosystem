import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';

export interface AdminDashboardSummary {
  total_users: number;
  active_accounts: number;
  suspended_accounts: number;
  pending_closure_requests: number;
  recent_contact_changes: number;
}

export async function getAdminDashboardSummary(
  client: AuthenticatedApiClient,
): Promise<AdminDashboardSummary> {
  const res = await client.get<AdminDashboardSummary>('/v1/admin/profile/dashboard/summary');
  return res.data;
}
