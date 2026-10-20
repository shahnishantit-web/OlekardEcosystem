'use client';

import { useEffect, useMemo, useState, useCallback } from 'react';
import {
  AreaChart,
  Area,
  XAxis,
  YAxis,
  Tooltip,
  ResponsiveContainer,
  CartesianGrid,
} from 'recharts';
import { useAuth } from '@/lib/auth/context';
import { searchAdminTransactions } from '@/src/modules/users/api/admin-portal';
import type { AdminTransaction } from '@/src/modules/users/api/admin-portal';

export type MetricChartPeriod = '7' | '30' | '90';

interface PeriodBucket {
  label: string;
  count: number;
  volume: number;
}

function bucketByPeriod(transactions: AdminTransaction[], period: MetricChartPeriod): PeriodBucket[] {
  const now = new Date();
  const days = parseInt(period, 10);
  const buckets: Map<string, PeriodBucket> = new Map();

  if (period === '90') {
    // Weekly buckets for 90-day view
    for (let w = 12; w >= 0; w--) {
      const d = new Date(now);
      d.setDate(now.getDate() - w * 7);
      const key = `${d.getFullYear()}-W${String(Math.ceil(d.getDate() / 7)).padStart(2, '0')}`;
      const label = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
      buckets.set(key, { label, count: 0, volume: 0 });
    }
    for (const tx of transactions) {
      const d = new Date(tx.created_at);
      const key = `${d.getFullYear()}-W${String(Math.ceil(d.getDate() / 7)).padStart(2, '0')}`;
      const bucket = buckets.get(key);
      if (bucket) { bucket.count += 1; bucket.volume += tx.amount; }
    }
  } else {
    // Daily buckets for 7/30-day view
    for (let i = days - 1; i >= 0; i--) {
      const d = new Date(now);
      d.setDate(now.getDate() - i);
      const key = d.toISOString().slice(0, 10);
      const label = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
      buckets.set(key, { label, count: 0, volume: 0 });
    }
    for (const tx of transactions) {
      const key = tx.created_at.slice(0, 10);
      const bucket = buckets.get(key);
      if (bucket) { bucket.count += 1; bucket.volume += tx.amount; }
    }
  }

  return Array.from(buckets.values());
}

const PERIOD_LABELS: Record<MetricChartPeriod, string> = {
  '7': 'Last 7 days',
  '30': 'Last 30 days',
  '90': 'Last 90 days',
};

const CustomTooltip = ({
  active,
  payload,
  label,
}: {
  active?: boolean;
  payload?: Array<{ value: number; name: string }>;
  label?: string;
}) => {
  if (!active || !payload?.length) return null;
  return (
    <div className="bg-ok-card-bg border border-ok-card-border rounded-lg px-3 py-2 text-xs shadow-lg">
      <div className="text-text-secondary mb-1">{label}</div>
      <div className="text-text-primary font-medium">{payload[0]?.value ?? 0} txns</div>
    </div>
  );
};

interface MetricChartProps {
  period?: MetricChartPeriod;
}

export function MetricChart({ period = '7' }: MetricChartProps) {
  const { authenticatedClient } = useAuth();
  const [transactions, setTransactions] = useState<AdminTransaction[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);
  const [periodUnavailable, setPeriodUnavailable] = useState(false);

  const fetchTransactions = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(false);
    setPeriodUnavailable(false);
    try {
      const days = parseInt(period, 10);
      const createdAfter = new Date(Date.now() - days * 24 * 60 * 60 * 1000).toISOString();
      const result = await searchAdminTransactions(authenticatedClient, {
        per_page: 500,
        created_after: createdAfter,
      } as Parameters<typeof searchAdminTransactions>[1]);
      setTransactions(result.data);
    } catch (e: unknown) {
      // If the API doesn't support date filtering, fall back to all available data
      const msg = e instanceof Error ? e.message : String(e);
      if (msg.includes('422') || msg.includes('invalid') || msg.includes('created_after')) {
        try {
          const result = await searchAdminTransactions(authenticatedClient, { per_page: 500 });
          setTransactions(result.data);
          setPeriodUnavailable(true);
        } catch {
          setError(true);
        }
      } else {
        setError(true);
      }
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, period]);

  useEffect(() => {
    void fetchTransactions();
  }, [fetchTransactions]);

  const data = useMemo(() => bucketByPeriod(transactions, period), [transactions, period]);

  const insufficientData = !loading && !error && data.filter(b => b.count > 0).length < 3;

  return (
    <section
      className="bg-ok-card-bg border border-ok-card-border rounded-[11px] p-4"
      aria-label={`Transaction volume — ${PERIOD_LABELS[period]}`}
    >
      <div className="flex items-center justify-between mb-4">
        <h2 className="text-sm font-semibold text-text-primary">Transaction Volume</h2>
        <span className="text-xs text-text-secondary">{PERIOD_LABELS[period]}</span>
      </div>

      {periodUnavailable && (
        <p className="text-xs text-ok-warning mb-2">
          Period filter not yet available — showing all transactions
        </p>
      )}

      {insufficientData && !periodUnavailable && (
        <p className="text-xs text-text-secondary mb-2">
          Insufficient data for this period
        </p>
      )}

      {loading && (
        <div className="h-40 flex items-center justify-center">
          <div className="h-2 w-48 rounded bg-neutral-200 animate-pulse" />
        </div>
      )}

      {error && (
        <div className="h-40 flex items-center justify-center">
          <div className="text-center">
            <p className="text-sm text-ok-error mb-2">Failed to load chart data</p>
            <button
              type="button"
              onClick={fetchTransactions}
              aria-label="Reload transaction chart"
              className="text-xs text-ok-primary hover:underline"
            >
              Reload
            </button>
          </div>
        </div>
      )}

      {!loading && !error && (
        <ResponsiveContainer width="100%" height={160}>
          <AreaChart data={data} margin={{ top: 4, right: 4, left: -20, bottom: 0 }}>
            <defs>
              <linearGradient id="txnGradient" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%" stopColor="#14b8a6" stopOpacity={0.25} />
                <stop offset="95%" stopColor="#14b8a6" stopOpacity={0} />
              </linearGradient>
            </defs>
            <CartesianGrid
              strokeDasharray="3 3"
              stroke="rgba(255,255,255,0.06)"
              vertical={false}
            />
            <XAxis
              dataKey="label"
              tick={{ fontSize: 10, fill: '#64748b' }}
              axisLine={false}
              tickLine={false}
              interval={period === '7' ? 0 : period === '30' ? 4 : 2}
            />
            <YAxis
              tick={{ fontSize: 10, fill: '#64748b' }}
              axisLine={false}
              tickLine={false}
              allowDecimals={false}
            />
            <Tooltip content={<CustomTooltip />} cursor={{ stroke: '#14b8a6', strokeWidth: 1, strokeDasharray: '3 3' }} />
            <Area
              type="monotone"
              dataKey="count"
              stroke="#14b8a6"
              strokeWidth={2}
              fill="url(#txnGradient)"
              dot={false}
              activeDot={{ r: 4, fill: '#14b8a6', strokeWidth: 0 }}
            />
          </AreaChart>
        </ResponsiveContainer>
      )}
    </section>
  );
}
