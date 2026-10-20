import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
import type {
  QaDashboard,
  QaTestRun,
  QaTestRunDetail,
} from '@/lib/api/admin-app-client';

export type {
  QaDashboard,
  QaCategoryRow,
  QaTestRun,
  QaTestRunDetail,
  QaTestResult,
  QaRunCounts,
} from '@/lib/api/admin-app-client';

export async function triggerQaRun(
  client: AuthenticatedApiClient,
  category: string,
  idempotencyKey: string,
): Promise<QaTestRun> {
  const res = await client.post<QaTestRun>(`/v1/qa/${category}`, {}, { idempotencyKey });
  return res.data;
}

export async function listQaRuns(
  client: AuthenticatedApiClient,
  params?: Record<string, string>,
): Promise<{ data: QaTestRun[]; meta: { total: number; per_page: number; current_page: number; last_page: number } }> {
  const qs = params ? '?' + new URLSearchParams(params).toString() : '';
  const res = await client.get<QaTestRun[]>(`/v1/qa/runs${qs}`);
  return { data: res.data, meta: {
    total: Number(res.meta?.total ?? res.data.length), per_page: Number(res.meta?.per_page ?? 20),
    current_page: Number(res.meta?.current_page ?? 1), last_page: Number(res.meta?.last_page ?? 1),
  } };
}

export async function getQaRun(
  client: AuthenticatedApiClient,
  id: string,
): Promise<QaTestRunDetail> {
  const res = await client.get<QaTestRunDetail>(`/v1/qa/runs/${id}`);
  return res.data;
}

export async function getQaDashboard(client: AuthenticatedApiClient): Promise<QaDashboard> {
  const res = await client.get<QaDashboard>('/v1/qa/dashboard');
  return res.data;
}
