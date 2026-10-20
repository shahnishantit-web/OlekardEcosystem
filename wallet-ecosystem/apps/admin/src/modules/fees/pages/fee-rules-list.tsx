'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Table } from '@/components/ui/Table';
import { useAuth } from '@/lib/auth/context';

interface FeeRule {
  id: string;
  transaction_type: string;
  fee_type: 'fixed' | 'percentage';
  rate: number | null;
  amount_minor: number | null;
  currency: string;
  subject_type: 'global' | 'customer' | 'agent';
  status: 'active' | 'expired' | 'deactivated' | 'superseded';
  valid_from: string;
  valid_to: string | null;
  created_at: string;
}

type StatusVariant = 'neutral' | 'success' | 'warning' | 'error' | 'info';

function statusVariant(status: string): StatusVariant {
  switch (status) {
    case 'active':      return 'success';
    case 'expired':     return 'warning';
    case 'deactivated': return 'error';
    case 'superseded':  return 'neutral';
    default:            return 'neutral';
  }
}

function feeDisplay(rule: FeeRule): string {
  if (rule.fee_type === 'percentage') {
    return `${rule.rate}%`;
  }
  return `${rule.currency} ${((rule.amount_minor ?? 0) / 100).toFixed(2)} (fixed)`;
}

export function FeeRulesListPage() {
  const { authenticatedClient } = useAuth();
  const router = useRouter();

  const [rules, setRules] = useState<FeeRule[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [typeFilter, setTypeFilter] = useState('');
  const [statusFilter, setStatusFilter] = useState('');

  const fetchRules = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const params = new URLSearchParams();
      if (typeFilter) params.set('transaction_type', typeFilter);
      if (statusFilter) params.set('status', statusFilter);
      const res = await authenticatedClient.get<FeeRule[]>(
        `/v1/fees/rules?${params.toString()}`
      );
      // Laravel resource collections expose the rows in the response's root
      // `data` field; pagination metadata is a sibling field.
      setRules(Array.isArray(res.data) ? res.data : []);
    } catch {
      setError('Failed to load fee rules.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, typeFilter, statusFilter]);

  useEffect(() => { void fetchRules(); }, [fetchRules]);

  const transactionTypes = [
    'transfer', 'funding', 'withdrawal', 'bill_payment',
    'qr_payment', 'cash_in', 'cash_out', 'settlement',
  ];

  return (
    <PageContainer>
        <PageHeader
          title="Fee Rules"
          actions={
            <Button
              variant="primary"
              onClick={() => router.push('/fees/rules/create')}
            >
              Create Rule
            </Button>
          }
        />

      <div className="mb-4 flex gap-3 flex-wrap">
        <select
          aria-label="Filter by transaction type"
          value={typeFilter}
          onChange={(e) => setTypeFilter(e.target.value)}
          className="rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
        >
          <option value="">All transaction types</option>
          {transactionTypes.map((t) => (
            <option key={t} value={t}>{t.replace('_', ' ')}</option>
          ))}
        </select>

        <select
          aria-label="Filter by status"
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
          className="rounded border border-border px-3 py-2 text-sm bg-surface text-text-primary"
        >
          <option value="">All statuses</option>
          <option value="active">Active</option>
          <option value="expired">Expired</option>
          <option value="deactivated">Deactivated</option>
          <option value="superseded">Superseded</option>
        </select>
      </div>

      {loading && <Loading label="Loading fee rules…" />}
      {!loading && error && <ErrorState message={error} onRetry={() => void fetchRules()} />}

      {!loading && !error && (
        <Card>
          <Table caption="Fee rules list">
            <thead>
              <tr className="border-b border-ok-border border-t border-ok-border bg-neutral-50">
                {['Transaction Type', 'Fee Type', 'Fee', 'Subject', 'Status', 'Valid From'].map((h) => (
                  <th key={h} scope="col" className="px-4 py-3 text-sm font-medium text-text-secondary">
                    {h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rules.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-4 py-8 text-center text-text-secondary text-sm">
                    No fee rules found.{' '}
                    <button
                      type="button"
                      className="underline text-ok-primary"
                      onClick={() => router.push('/fees/rules/create')}
                    >
                      Create your first fee rule
                    </button>
                  </td>
                </tr>
              ) : (
                rules.map((r) => (
                  <tr
                    key={r.id}
                    className="border-b border-[#f0f1f4] hover:bg-ok-hover-bg cursor-pointer"
                    onClick={() => router.push(`/fees/rules/${r.id}`)}
                    tabIndex={0}
                    role="row"
                    aria-label={`View fee rule: ${r.transaction_type.replace(/_/g, ' ')} ${r.fee_type}`}
                    onKeyDown={(e) => {
                      if (e.key === 'Enter' || e.key === ' ') router.push(`/fees/rules/${r.id}`);
                    }}
                  >
                    <td className="px-4 py-3 text-sm text-text-primary">{r.transaction_type.replace(/_/g, ' ')}</td>
                    <td className="px-4 py-3 text-sm text-text-primary">{r.fee_type}</td>
                    <td className="px-4 py-3 text-sm text-text-primary">{feeDisplay(r)}</td>
                    <td className="px-4 py-3 text-sm text-text-primary">{r.subject_type}</td>
                    <td className="px-4 py-3"><Badge label={r.status.toUpperCase()} variant={statusVariant(r.status)} /></td>
                    <td className="px-4 py-3 text-sm text-text-primary">{new Date(r.valid_from).toLocaleDateString()}</td>
                  </tr>
                ))
              )}
            </tbody>
          </Table>
        </Card>
      )}
    </PageContainer>
  );
}
