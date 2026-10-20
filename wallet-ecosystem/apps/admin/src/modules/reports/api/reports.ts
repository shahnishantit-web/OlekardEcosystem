import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
import type { ReconciliationBatch } from '@/lib/api/admin-app-client';

export type ReportJobStatus = 'requested' | 'processing' | 'complete' | 'failed';

export interface ReportJob {
  id: string;
  report_type: string;
  status: string;
  row_count: number | null;
  failure_reason: string | null;
  filters: Record<string, unknown>;
  result_data: Record<string, unknown> | null;
  as_of_at: string | null;
  created_at: string;
  started_at: string | null;
  completed_at: string | null;
}

export interface ReportExport {
  id: string;
  job_id: string;
  format: 'csv' | 'excel';
  status: 'pending' | 'ready' | 'purged';
  file_size_bytes: number | null;
  row_count: number | null;
  download_url: string | null;
  token_expires_at: string | null;
  file_retention_expires_at: string | null;
}

export interface ReportSchedule {
  id: string;
  name: string;
  report_type: string;
  cadence: 'daily' | 'weekly' | 'monthly';
  cadence_time: string;
  cadence_day_of_week: number | null;
  cadence_day_of_month: number | null;
  next_run_at: string | null;
  status: 'active' | 'inactive';
  recipients: Array<{ user_id: string; email?: string }>;
  email_notification_enabled: boolean;
  filters?: Record<string, unknown>;
}

export interface ReconciliationSummary {
  total_transactions: number;
  matched_count: number;
  unmatched_count: number;
  exception_amount_bwp: number;
  matched_pct: number;
}

/** Maps AdminAppClient status values (pending/ready) to the canonical set. */
export function normaliseJobStatus(raw: string): ReportJobStatus {
  if (raw === 'pending') return 'requested';
  if (raw === 'ready') return 'complete';
  return raw as ReportJobStatus;
}

export async function submitReportJob(
  client: AuthenticatedApiClient,
  payload: { report_type: string; filters: { date_range_start: string; date_range_end: string } },
): Promise<ReportJob> {
  const res = await client.post<ReportJob>('/v1/reports/jobs', payload, {
    idempotencyKey: crypto.randomUUID(),
  });
  return res.data;
}

export async function getReportJob(client: AuthenticatedApiClient, id: string): Promise<ReportJob> {
  const res = await client.get<ReportJob>(`/v1/reports/jobs/${id}`);
  return res.data;
}

/** Step 1 of the two-step download: creates a new export job for the given format. */
export async function requestExport(
  client: AuthenticatedApiClient,
  jobId: string,
  format: 'csv' | 'excel',
): Promise<ReportExport> {
  const res = await client.post<ReportExport>(`/v1/reports/jobs/${jobId}/exports`, { format });
  return res.data;
}

/** Step 2 of the two-step download: polls until status === 'ready', then returns the export. */
export async function getExport(client: AuthenticatedApiClient, exportId: string): Promise<ReportExport> {
  const res = await client.get<ReportExport>(`/v1/reports/exports/${exportId}`);
  return res.data;
}

/** Re-activates or updates a schedule via PUT (E24 contract). */
export async function updateSchedule(
  client: AuthenticatedApiClient,
  scheduleId: string,
  payload: Partial<ReportSchedule>,
): Promise<ReportSchedule> {
  const res = await client.put<ReportSchedule>(`/v1/reports/schedules/${scheduleId}`, payload);
  return res.data;
}

/** Derives KPI summary from a list of reconciliation batches (no extra API call needed). */
export function deriveReconciliationSummary(batches: ReconciliationBatch[]): ReconciliationSummary {
  const total = batches.reduce((s, b) => s + b.total_transactions, 0);
  const matched = batches.reduce((s, b) => s + b.matched_count, 0);
  const unmatched = batches.reduce((s, b) => s + b.unmatched_count, 0);
  const discrepancyMinor = batches.reduce((s, b) => s + b.discrepancy_amount_minor_units, 0);
  return {
    total_transactions: total,
    matched_count: matched,
    unmatched_count: unmatched,
    exception_amount_bwp: discrepancyMinor / 100,
    matched_pct: total > 0 ? (matched / total) * 100 : 0,
  };
}
