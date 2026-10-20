# Data Model: ADM-P1-015 — Reports & Analytics

**Date**: 2026-10-14

All types are TypeScript interfaces for the admin Next.js frontend. Backend schemas are defined in `specs/024-reporting-analytics/contracts/reporting.yaml`.

---

## ReportJobStatus

```typescript
type ReportJobStatus = 'requested' | 'processing' | 'complete' | 'failed';
```

Canonical normalised status. Maps:
- `pending` | `requested` → `'requested'`
- `processing` → `'processing'`
- `ready` | `complete` → `'complete'`
- `failed` → `'failed'`

---

## ReportJob (direct API — `/v1/reports/jobs`)

```typescript
interface ReportJob {
  id: string;
  report_type: string;
  status: string;          // raw; normalise with normaliseJobStatus()
  row_count: number | null;
  failure_reason: string | null;
  filters: Record<string, unknown>;
  result_data: Record<string, unknown> | null;
  as_of_at: string | null;
  created_at: string;
  started_at: string | null;
  completed_at: string | null;
}
```

Source: `GET /v1/reports/jobs`, `GET /v1/reports/jobs/{id}`, `POST /v1/reports/jobs`

---

## AdminAppReportJob (AdminAppClient — `/v1/admin-app/reports`)

```typescript
// Already defined in admin-app-client.ts as ReportJob:
interface AdminAppReportJob {
  report_id: string;       // note: report_id not id
  report_type: string;
  status: string;          // raw; may be 'pending'/'ready' — normalise
  download_url: string | null;
  created_at: string;
}
```

Source: `AdminAppClient.createReport()`, `AdminAppClient.getReport(reportId)`

---

## ReportExport

```typescript
interface ReportExport {
  id: string;
  job_id: string;
  format: 'csv' | 'excel';
  status: 'pending' | 'ready' | 'purged';
  file_size_bytes: number | null;
  row_count: number | null;
  download_url: string | null;         // non-null when status === 'ready'
  token_expires_at: string | null;
  file_retention_expires_at: string | null;
}
```

Source: `POST /v1/reports/jobs/{jobId}/exports`, `GET /v1/reports/exports/{exportId}`

---

## ReportSchedule

```typescript
interface ReportSchedule {
  id: string;
  name: string;
  report_type: string;
  cadence: 'daily' | 'weekly' | 'monthly';
  cadence_time: string;               // HH:MM
  cadence_day_of_week: number | null; // 0–6 (Sun–Sat), set when cadence='weekly'
  cadence_day_of_month: number | null;// 1–28, set when cadence='monthly'
  next_run_at: string | null;
  status: 'active' | 'inactive';
  recipients: Array<{ user_id: string; email?: string }>;
  email_notification_enabled: boolean;
  filters?: Record<string, unknown>;
}
```

Source: `GET /v1/reports/schedules`, `POST /v1/reports/schedules`, `PUT /v1/reports/schedules/{id}`

### State Transitions

```
active → inactive  (DELETE /v1/reports/schedules/{id})
inactive → active  (PUT /v1/reports/schedules/{id} with status: 'active')
```

---

## ReconciliationBatch (existing — from AdminAppClient)

```typescript
// Already defined in admin-app-client.ts
interface ReconciliationBatch {
  batch_id: string;
  period_start: string;
  period_end: string;
  status: string;
  total_transactions: number;
  matched_count: number;
  unmatched_count: number;
  discrepancy_amount_minor_units: number;
  currency_code: string;
  run_at: string | null;
  completed_at: string | null;
  unmatched_transaction_ids?: string[] | null;
}
```

---

## ReconciliationSummary (derived client-side)

```typescript
interface ReconciliationSummary {
  total_transactions: number;       // sum of batch.total_transactions
  matched_count: number;            // sum of batch.matched_count
  unmatched_count: number;          // sum of batch.unmatched_count
  exception_amount_bwp: number;     // sum(discrepancy_amount_minor_units) / 100
  matched_pct: number;              // (matched_count / total_transactions) * 100, or 0
}
```

Computed in `reconciliation-report.tsx` from the batch list; no additional API call.

---

## CreateSchedulePayload

```typescript
interface CreateSchedulePayload {
  name: string;                       // required, non-empty
  report_type: string;                // required
  cadence: 'daily' | 'weekly' | 'monthly'; // required
  cadence_time: string;               // HH:MM, required
  cadence_day_of_week?: number;       // 0–6, required when cadence='weekly'
  cadence_day_of_month?: number;      // 1–28, required when cadence='monthly'
  email_notification_enabled: boolean;
  filters?: {
    date_range_start?: string;        // YYYY-MM-DD
    date_range_end?: string;          // YYYY-MM-DD
  };
}
```

---

## Validation Rules

| Field | Rule |
|-------|------|
| `date_range_start` / `date_range_end` | End must not be before start; both required for generate form |
| `cadence_time` | Matches `/^\d{2}:\d{2}$/`; HH 00–23, MM 00–59 |
| `cadence_day_of_week` | Integer 0–6; required when cadence='weekly' |
| `cadence_day_of_month` | Integer 1–28; required when cadence='monthly' |
| `name` | Non-empty string after trim |
| Polling max | 36 iterations (3 minutes at 5s interval) |
| Export format | Enum: `'csv' | 'excel'` |
