'use client';

import { useState, useMemo } from 'react';
import Link from 'next/link';
import { useAuth } from '@/lib/auth/context';
import { PaymentApiClient } from '@/lib/api/payment';
import type { ExportRequest, PaymentIntentSummary, HistoryParams } from '@/lib/api/payment';
import { useQuery } from '@tanstack/react-query';
import { ExportProgressBar } from '@/src/modules/payment/components/ExportProgressBar';
import { usePaymentExport } from '@/src/modules/payment/hooks/usePaymentExport';
import { CurrencyAmount } from '@/src/modules/payment/components/CurrencyAmount';

interface CurrencyAggregate {
  currency_code: string;
  total_intents: number;
  total_amount_minor: number;
  fulfilled_count: number;
  fulfilled_amount_minor: number;
  failed_count: number;
  pending_count: number;
  freshness_cutoff: string;
}

function aggregateByCurrency(intents: PaymentIntentSummary[]): CurrencyAggregate[] {
  const map = new Map<string, CurrencyAggregate>();
  for (const intent of intents) {
    const cur = intent.currency_code;
    if (!map.has(cur)) {
      map.set(cur, {
        currency_code: cur,
        total_intents: 0,
        total_amount_minor: 0,
        fulfilled_count: 0,
        fulfilled_amount_minor: 0,
        failed_count: 0,
        pending_count: 0,
        freshness_cutoff: intent.updated_at,
      });
    }
    const agg = map.get(cur)!;
    agg.total_intents += 1;
    agg.total_amount_minor += intent.amount_minor;
    if (intent.status === 'fulfilled') {
      agg.fulfilled_count += 1;
      agg.fulfilled_amount_minor += intent.amount_minor;
    } else if (intent.status === 'failed' || intent.status === 'cancelled') {
      agg.failed_count += 1;
    } else if (['created', 'captured', 'pending_ledger'].includes(intent.status)) {
      agg.pending_count += 1;
    }
    if (intent.updated_at > agg.freshness_cutoff) {
      agg.freshness_cutoff = intent.updated_at;
    }
  }
  return Array.from(map.values()).sort((a, b) => a.currency_code.localeCompare(b.currency_code));
}

function todayIso()  { return new Date().toISOString().slice(0, 10); }
function thirtyDaysAgoIso() {
  const d = new Date(); d.setDate(d.getDate() - 30); return d.toISOString().slice(0, 10);
}

export function PaymentReportsPage() {
  const { hasPermission, authenticatedClient } = useAuth();

  const [dateFrom, setDateFrom] = useState(thirtyDaysAgoIso());
  const [dateTo, setDateTo]     = useState(todayIso());
  const [currency, setCurrency]  = useState('');
  const [applied, setApplied]    = useState({ dateFrom: thirtyDaysAgoIso(), dateTo: todayIso(), currency: '' });

  const queryParams: HistoryParams = {
    date_from: applied.dateFrom,
    date_to:   applied.dateTo,
    per_page:  200,
    ...(applied.currency ? { currency: applied.currency } : {}),
  };

  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['payment-reports', queryParams],
    enabled:  !!authenticatedClient,
    queryFn:  async () => {
      const client = new PaymentApiClient(authenticatedClient!);
      const res = await client.getPaymentHistory(queryParams);
      return (res.data as unknown as { data: PaymentIntentSummary[] }).data ?? [];
    },
  });

  const rows: CurrencyAggregate[] = useMemo(
    () => (data ? aggregateByCurrency(data) : []),
    [data],
  );

  const { exportStatus, downloadUrl, exportId, trigger: triggerExport, reset: resetExport } = usePaymentExport();

  if (!hasPermission('payment.export.read.organisation')) {
    return (
      <div role="alert" className="rounded-lg border border-ok-error-border bg-ok-error-bg p-6">
        <p className="font-semibold text-ok-error-fg">Access Denied</p>
        <p className="mt-1 text-sm text-ok-error-fg">
          You do not have permission to view payment reports.
        </p>
      </div>
    );
  }

  const handleApply = () => {
    setApplied({ dateFrom, dateTo, currency });
  };

  const handleExport = async () => {
    if (exportStatus !== 'idle') { resetExport(); return; }
    const payload: ExportRequest = {
      date_from: applied.dateFrom,
      date_to:   applied.dateTo,
      format:    'csv',
      ...(applied.currency ? { currency: applied.currency } : {}),
    };
    await triggerExport(payload);
  };

  const totalRows = rows.reduce((sum, r) => sum + r.total_intents, 0);

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-semibold">Payment Reports</h1>

      {/* Filters */}
      <div className="flex flex-wrap items-end gap-4 rounded-lg border bg-card p-4">
        <div className="flex flex-col gap-1">
          <label htmlFor="rpt-from" className="text-sm font-medium">From</label>
          <input
            id="rpt-from"
            type="date"
            value={dateFrom}
            onChange={e => setDateFrom(e.target.value)}
            max={dateTo}
            className="rounded border px-2 py-1 text-sm"
          />
        </div>
        <div className="flex flex-col gap-1">
          <label htmlFor="rpt-to" className="text-sm font-medium">To</label>
          <input
            id="rpt-to"
            type="date"
            value={dateTo}
            onChange={e => setDateTo(e.target.value)}
            min={dateFrom}
            max={todayIso()}
            className="rounded border px-2 py-1 text-sm"
          />
        </div>
        <div className="flex flex-col gap-1">
          <label htmlFor="rpt-currency" className="text-sm font-medium">Currency</label>
          <input
            id="rpt-currency"
            type="text"
            placeholder="All"
            value={currency}
            onChange={e => setCurrency(e.target.value.toUpperCase())}
            maxLength={3}
            className="w-24 rounded border px-2 py-1 text-sm uppercase"
          />
        </div>
        <button
          type="button"
          onClick={handleApply}
          className="rounded bg-ok-primary px-4 py-2 text-sm font-medium text-white hover:bg-ok-primary/90"
        >
          Generate Report
        </button>
        <button
          type="button"
          onClick={() => void handleExport()}
          disabled={exportStatus !== 'idle' && exportStatus !== 'error'}
          className="rounded border border-ok-primary px-4 py-2 text-sm font-medium text-ok-primary hover:bg-ok-primary/10 disabled:opacity-50"
          aria-label="Export to CSV"
        >
          {exportStatus === 'idle' || exportStatus === 'error' ? 'Export to CSV' : 'Exporting…'}
        </button>
      </div>

      {/* Export progress */}
      <ExportProgressBar
        status={exportStatus}
        rowCount={totalRows}
        onRetry={resetExport}
        {...(downloadUrl !== null ? { downloadUrl } : {})}
      />
      {exportStatus === 'error' && exportId && (
        <p className="text-sm text-ok-muted">
          Export taking longer than expected. Export ID:{' '}
          <code className="font-mono text-xs">{exportId}</code>
        </p>
      )}

      {isLoading && (
        <div role="status" className="py-8 text-center text-sm text-ok-muted">Loading report…</div>
      )}

      {error && !isLoading && (
        <div role="alert" className="rounded border border-ok-error-border bg-ok-error-bg px-4 py-3">
          <p className="text-sm text-ok-error-fg">Failed to load report data.</p>
          <button type="button" onClick={() => void refetch()} className="mt-1 text-sm underline text-ok-error-fg">
            Retry
          </button>
        </div>
      )}

      {!isLoading && !error && rows.length === 0 && data && (
        <div className="rounded border bg-card p-8 text-center text-sm text-ok-muted">
          No payment data for the selected period and filters.
        </div>
      )}

      {!isLoading && rows.length > 0 && (
        <div className="overflow-x-auto rounded-lg border">
          <table className="w-full text-sm">
            <thead className="bg-ok-muted/10 text-left text-xs font-semibold uppercase text-ok-muted">
              <tr>
                <th className="px-4 py-3">Currency</th>
                <th className="px-4 py-3 text-right">Total</th>
                <th className="px-4 py-3 text-right">Volume</th>
                <th className="px-4 py-3 text-right">Fulfilled</th>
                <th className="px-4 py-3 text-right">Fulfilled Amount</th>
                <th className="px-4 py-3 text-right">Failed</th>
                <th className="px-4 py-3 text-right">Pending</th>
                <th className="px-4 py-3">Freshness Cutoff</th>
                <th className="px-4 py-3">Audit</th>
              </tr>
            </thead>
            <tbody className="divide-y">
              {rows.map(row => (
                <tr key={row.currency_code} className="hover:bg-ok-muted/5">
                  <td className="px-4 py-3 font-mono font-semibold">{row.currency_code}</td>
                  <td className="px-4 py-3 text-right">{row.total_intents.toLocaleString()}</td>
                  <td className="px-4 py-3 text-right">
                    <CurrencyAmount amountMinor={row.total_amount_minor} currency={row.currency_code} />
                  </td>
                  <td className="px-4 py-3 text-right">{row.fulfilled_count.toLocaleString()}</td>
                  <td className="px-4 py-3 text-right">
                    <CurrencyAmount amountMinor={row.fulfilled_amount_minor} currency={row.currency_code} />
                  </td>
                  <td className="px-4 py-3 text-right">{row.failed_count.toLocaleString()}</td>
                  <td className="px-4 py-3 text-right">{row.pending_count.toLocaleString()}</td>
                  <td className="px-4 py-3 text-xs text-ok-muted">
                    {new Date(row.freshness_cutoff).toLocaleDateString()}
                  </td>
                  <td className="px-4 py-3">
                    <Link
                      href={`/audit?entity_type=payment_report&entity_id=${row.currency_code}`}
                      className="text-xs text-ok-primary hover:underline"
                      aria-label={`Audit trail for ${row.currency_code}`}
                    >
                      Audit
                    </Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          <p className="border-t bg-ok-muted/5 px-4 py-2 text-xs text-ok-muted">
            Each currency is shown as a separate row — totals are never aggregated across currencies.
            Freshness cutoff is the latest updated_at timestamp in the queried period for each currency.
          </p>
        </div>
      )}
    </div>
  );
}
