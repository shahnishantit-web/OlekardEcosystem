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

interface TicketType {
  id: string; name: string; price_minor: number; currency: string;
  capacity: number; sold_count: number; is_active: boolean;
}
interface Campaign {
  id: string; organisation_id: string; service_type: string; title: string;
  description: string | null; status: string; reference: string; currency: string;
  contribution_amount_minor: number | null; metadata: Record<string, string>;
  starts_at: string | null; ends_at: string | null; opened_at: string | null;
  closed_at: string | null; settled_at: string | null; ticket_types: TicketType[];
}
interface Dashboard {
  campaign: Campaign; orders_confirmed: number; orders_pending: number; orders_failed: number;
  total_collected_minor: number; currency: string; admitted_count: number;
  ticket_types_summary: Array<{ id: string; name: string; price_minor: number; capacity: number; sold_count: number }>;
}
interface Order {
  id: string; consumer_id: string; status: string; total_amount_minor: number;
  currency: string; ticket_code: string | null; ticket_used_at: string | null; created_at: string;
}

export function CampaignDetailPage({ campaignId }: { campaignId: string }) {
  const { authenticatedClient, hasPermission } = useAuth();
  const router = useRouter();

  const [campaign, setCampaign]   = useState<Campaign | null>(null);
  const [dashboard, setDashboard] = useState<Dashboard | null>(null);
  const [orders, setOrders]       = useState<Order[]>([]);
  const [loading, setLoading]     = useState(true);
  const [error, setError]         = useState<string | null>(null);
  const [actioning, setActioning] = useState(false);

  // Settlement form (funeral)
  const [showSettle, setShowSettle] = useState(false);
  const [settleForm, setSettleForm] = useState({ method: '', reference: '', amount_minor: 0, notes: '' });

  // Ticket validation (events)
  const [ticketCode, setTicketCode]   = useState('');
  const [validateResult, setValidateResult] = useState<{ valid: boolean; reason?: string; admitted_at?: string } | null>(null);

  const canManage = hasPermission('community.campaigns.manage');

  const loadAll = useCallback(async () => {
    if (!authenticatedClient) return;
    try {
      const [c, d, o] = await Promise.all([
        authenticatedClient.get<Campaign>(`/v1/community/admin/campaigns/${campaignId}`),
        authenticatedClient.get<Dashboard>(`/v1/community/admin/campaigns/${campaignId}/dashboard`),
        authenticatedClient.get<{ data: Order[] }>(`/v1/community/admin/campaigns/${campaignId}/orders`),
      ]);
      setCampaign(c.data);
      setDashboard(d.data);
      setOrders(o.data.data ?? []);
    } catch {
      setError('Failed to load campaign.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, campaignId]);

  useEffect(() => { void loadAll(); }, [loadAll]);

  async function transition(action: 'open' | 'close') {
    if (!authenticatedClient) return;
    if (!window.confirm(`${action === 'open' ? 'Open' : 'Close'} this campaign?`)) return;
    setActioning(true);
    try {
      await authenticatedClient.post(`/v1/community/admin/campaigns/${campaignId}/${action}`, {});
      await loadAll();
    } catch {
      setError(`Failed to ${action} campaign.`);
    } finally {
      setActioning(false);
    }
  }

  async function submitSettle(e: React.FormEvent) {
    e.preventDefault();
    if (!authenticatedClient) return;
    setActioning(true);
    try {
      await authenticatedClient.post(`/v1/community/admin/campaigns/${campaignId}/settle`, settleForm);
      setShowSettle(false);
      await loadAll();
    } catch {
      setError('Failed to settle campaign.');
    } finally {
      setActioning(false);
    }
  }

  async function validateTicket(e: React.FormEvent) {
    e.preventDefault();
    if (!authenticatedClient) return;
    try {
      const res = await authenticatedClient.post<{ valid: boolean; reason?: string; admitted_at?: string }>(
        `/v1/community/admin/campaigns/${campaignId}/validate-ticket`,
        { ticket_code: ticketCode }
      );
      setValidateResult(res.data);
    } catch {
      setValidateResult({ valid: false, reason: 'Validation failed.' });
    }
  }

  if (loading) return <Loading label="Loading campaign…" />;
  if (error && !campaign) return <ErrorState message={error} onRetry={() => void loadAll()} />;
  if (!campaign || !dashboard) return null;

  const isFuneral = campaign.service_type === 'funeral';
  const funeralActionsEnabled = false; // P24 HTTP and payout prerequisites remain closed.
  const isEvents  = campaign.service_type === 'events';
  const totalBwp  = (dashboard.total_collected_minor / 100).toFixed(2);

  return (
    <PageContainer>
      <PageHeader
        title={campaign.title}
        description={`${campaign.service_type.toUpperCase()} · ${campaign.reference}`}
        actions={canManage && !isFuneral ? (
          <div className="flex gap-2 flex-wrap">
            {campaign.status === 'draft'  && <Button variant="primary"    onClick={() => void transition('open')}  disabled={actioning}>Open</Button>}
            {campaign.status === 'active' && <Button variant="secondary"  onClick={() => void transition('close')} disabled={actioning}>Close</Button>}
            {campaign.status === 'closed' && <Button variant="primary"    onClick={() => setShowSettle(true)}      disabled={actioning}>Record Settlement</Button>}
          </div>
        ) : undefined}
      />

      {error && <p role="alert" className="mb-3 text-sm text-red-700">{error}</p>}

      {isFuneral && <p role="status" className="mb-4 rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">Funeral Assistance actions and disbursement are unavailable while P24 verification is in progress.</p>}

      {/* Status bar */}
      <div className="mb-4 flex gap-4 items-center flex-wrap">
        <Badge
          label={campaign.status.toUpperCase()}
          variant={campaign.status === 'active' ? 'success' : campaign.status === 'closed' ? 'warning' : campaign.status === 'settled' ? 'info' : 'neutral'}
        />
        {campaign.opened_at && <span className="text-sm text-text-secondary">Opened {new Date(campaign.opened_at).toLocaleDateString()}</span>}
        {campaign.closed_at && <span className="text-sm text-text-secondary">Closed {new Date(campaign.closed_at).toLocaleDateString()}</span>}
      </div>

      {/* Dashboard stats */}
      <div className="grid grid-cols-2 gap-3 mb-4 sm:grid-cols-4">
        {[
          { label: 'Confirmed', value: String(dashboard.orders_confirmed), color: 'text-green-700' },
          { label: 'Pending', value: String(dashboard.orders_pending), color: 'text-amber-700' },
          { label: isFuneral ? 'Total Collected' : 'Revenue', value: `BWP ${totalBwp}`, color: 'text-text-primary' },
          isEvents
            ? { label: 'Admitted', value: String(dashboard.admitted_count), color: 'text-blue-700' }
            : { label: 'Failed', value: String(dashboard.orders_failed), color: 'text-red-700' },
        ].map((s) => (
          <Card key={s.label}>
            <p className="text-xs text-text-secondary">{s.label}</p>
            <p className={`text-xl font-bold mt-1 ${s.color}`}>{s.value}</p>
          </Card>
        ))}
      </div>

      {/* Events: ticket types summary */}
      {isEvents && dashboard.ticket_types_summary.length > 0 && (
        <Card className="mb-4">
          <h2 className="text-sm font-semibold text-text-primary mb-3">Ticket Types</h2>
          <Table caption="Ticket type sales summary">
            <thead>
              <tr className="border-b border-ok-border bg-neutral-50">
                {['Name', 'Price', 'Sold', 'Capacity', 'Remaining'].map((h) => (
                  <th key={h} scope="col" className="px-4 py-2 text-xs font-medium text-text-secondary">{h}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {dashboard.ticket_types_summary.map((tt) => {
                const remaining = tt.capacity === 0 ? '∞' : String(tt.capacity - tt.sold_count);
                return (
                  <tr key={tt.id} className="border-b border-[#f0f1f4]">
                    <td className="px-4 py-2 text-sm font-medium text-text-primary">{tt.name}</td>
                    <td className="px-4 py-2 text-sm text-text-primary">BWP {(tt.price_minor / 100).toFixed(2)}</td>
                    <td className="px-4 py-2 text-sm text-green-700 font-medium">{tt.sold_count}</td>
                    <td className="px-4 py-2 text-sm text-text-secondary">{tt.capacity === 0 ? 'Unlimited' : tt.capacity}</td>
                    <td className="px-4 py-2 text-sm text-text-secondary">{remaining}</td>
                  </tr>
                );
              })}
            </tbody>
          </Table>
        </Card>
      )}

      {/* Funeral: metadata */}
      {isFuneral && (
        <Card className="mb-4">
          <h2 className="text-sm font-semibold text-text-primary mb-3">Collection Details</h2>
          <dl className="space-y-2 text-sm">
            {campaign.metadata?.bereaved_household && (
              <div className="flex justify-between">
                <dt className="text-text-secondary">Bereaved Household</dt>
                <dd className="font-medium text-text-primary">{campaign.metadata.bereaved_household}</dd>
              </div>
            )}
            {campaign.metadata?.funeral_date && (
              <div className="flex justify-between">
                <dt className="text-text-secondary">Funeral Date</dt>
                <dd className="font-medium text-text-primary">{campaign.metadata.funeral_date}</dd>
              </div>
            )}
            <div className="flex justify-between">
              <dt className="text-text-secondary">Contribution Amount</dt>
              <dd className="font-medium text-text-primary">BWP {((campaign.contribution_amount_minor ?? 2000) / 100).toFixed(2)}</dd>
            </div>
          </dl>
          {campaign.metadata?.settlement && (
            <div className="mt-3 p-3 rounded bg-green-50 border border-green-200 text-sm">
              <p className="font-semibold text-green-800">Settlement Recorded</p>
              <p className="text-green-700 mt-1">Method: {(campaign.metadata.settlement as unknown as Record<string, string>).method}</p>
              <p className="text-green-700">Reference: {(campaign.metadata.settlement as unknown as Record<string, string>).reference}</p>
            </div>
          )}
        </Card>
      )}

      {/* Events: venue/sponsor */}
      {isEvents && (
        <Card className="mb-4">
          <h2 className="text-sm font-semibold text-text-primary mb-3">Event Details</h2>
          <dl className="space-y-2 text-sm">
            {campaign.metadata?.venue && (
              <div className="flex justify-between">
                <dt className="text-text-secondary">Venue</dt>
                <dd className="font-medium text-text-primary">{campaign.metadata.venue}</dd>
              </div>
            )}
            {campaign.metadata?.sponsor && (
              <div className="flex justify-between">
                <dt className="text-text-secondary">Sponsor</dt>
                <dd className="font-medium text-text-primary">{campaign.metadata.sponsor}</dd>
              </div>
            )}
            {campaign.starts_at && (
              <div className="flex justify-between">
                <dt className="text-text-secondary">Start</dt>
                <dd className="font-medium text-text-primary">{new Date(campaign.starts_at).toLocaleString()}</dd>
              </div>
            )}
          </dl>
        </Card>
      )}

      {/* Events: ticket validation */}
      {isEvents && campaign.status === 'active' && canManage && (
        <Card className="mb-4">
          <h2 className="text-sm font-semibold text-text-primary mb-3">Ticket Validation (Gate)</h2>
          <form onSubmit={(e) => void validateTicket(e)} className="flex gap-2">
            <input
              type="text"
              value={ticketCode}
              onChange={(e) => { setTicketCode(e.target.value.toUpperCase()); setValidateResult(null); }}
              placeholder="Enter ticket code (e.g. ABCD-EFGH-IJKL)"
              className="flex-1 rounded border border-border px-3 py-2 text-sm font-mono bg-surface text-text-primary uppercase"
              maxLength={20}
            />
            <Button type="submit" variant="primary">Validate</Button>
          </form>
          {validateResult && (
            <div className={`mt-2 p-3 rounded text-sm font-medium ${validateResult.valid ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'}`}>
              {validateResult.valid
                ? `✓ ADMIT — Ticket valid. Admitted at ${new Date(validateResult.admitted_at!).toLocaleTimeString()}`
                : `✗ DENY — ${validateResult.reason}`}
            </div>
          )}
        </Card>
      )}

      {/* Settlement form (funeral, closed) */}
      {showSettle && isFuneral && funeralActionsEnabled && (
        <Card className="mb-4 border border-amber-300">
          <h2 className="text-sm font-semibold text-text-primary mb-3">Record Settlement / Disbursement</h2>
          <form onSubmit={(e) => void submitSettle(e)} className="space-y-3">
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block text-xs font-medium text-text-primary mb-1">Method *</label>
                <input type="text" required placeholder="e.g. OleKard Wallet" value={settleForm.method}
                  onChange={(e) => setSettleForm((f) => ({ ...f, method: e.target.value }))}
                  className="w-full rounded border border-border px-2 py-1 text-sm bg-surface text-text-primary" />
              </div>
              <div>
                <label className="block text-xs font-medium text-text-primary mb-1">Reference *</label>
                <input type="text" required value={settleForm.reference}
                  onChange={(e) => setSettleForm((f) => ({ ...f, reference: e.target.value }))}
                  className="w-full rounded border border-border px-2 py-1 text-sm bg-surface text-text-primary" />
              </div>
              <div>
                <label className="block text-xs font-medium text-text-primary mb-1">Amount (minor units) *</label>
                <input type="number" required min={1} value={settleForm.amount_minor}
                  onChange={(e) => setSettleForm((f) => ({ ...f, amount_minor: parseInt(e.target.value, 10) }))}
                  className="w-full rounded border border-border px-2 py-1 text-sm bg-surface text-text-primary" />
                <p className="text-xs text-text-secondary">Max: BWP {totalBwp}</p>
              </div>
              <div>
                <label className="block text-xs font-medium text-text-primary mb-1">Notes</label>
                <input type="text" value={settleForm.notes}
                  onChange={(e) => setSettleForm((f) => ({ ...f, notes: e.target.value }))}
                  className="w-full rounded border border-border px-2 py-1 text-sm bg-surface text-text-primary" />
              </div>
            </div>
            <div className="flex gap-2">
              <Button type="submit" variant="primary" disabled={actioning}>Confirm Settlement</Button>
              <Button type="button" variant="ghost" onClick={() => setShowSettle(false)}>Cancel</Button>
            </div>
          </form>
        </Card>
      )}

      {/* Orders list */}
      <Card>
        <h2 className="text-sm font-semibold text-text-primary mb-3">
          {isFuneral ? 'Contributions' : 'Ticket Orders'}
        </h2>
        <Table caption={isFuneral ? 'Household contributions' : 'Ticket orders'}>
          <thead>
            <tr className="border-b border-ok-border bg-neutral-50">
              {(isEvents
                ? ['Consumer', 'Ticket Code', 'Status', 'Amount', 'Used', 'Date']
                : ['Consumer', 'Status', 'Amount', 'Date']
              ).map((h) => (
                <th key={h} scope="col" className="px-4 py-2 text-xs font-medium text-text-secondary">{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {orders.length === 0 ? (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-text-secondary text-sm">No orders yet.</td></tr>
            ) : (
              orders.map((o) => (
                <tr key={o.id} className="border-b border-[#f0f1f4]">
                  <td className="px-4 py-2 text-xs font-mono text-text-secondary">{o.consumer_id.slice(0, 8)}…</td>
                  {isEvents && <td className="px-4 py-2 text-xs font-mono text-text-primary">{o.ticket_code ?? '—'}</td>}
                  <td className="px-4 py-2">
                    <Badge
                      label={o.status.toUpperCase()}
                      variant={o.status === 'confirmed' ? 'success' : o.status === 'pending' ? 'warning' : 'neutral'}
                    />
                  </td>
                  <td className="px-4 py-2 text-sm text-text-primary">BWP {(o.total_amount_minor / 100).toFixed(2)}</td>
                  {isEvents && <td className="px-4 py-2 text-xs text-text-secondary">{o.ticket_used_at ? new Date(o.ticket_used_at).toLocaleTimeString() : '—'}</td>}
                  <td className="px-4 py-2 text-xs text-text-secondary">{new Date(o.created_at).toLocaleDateString()}</td>
                </tr>
              ))
            )}
          </tbody>
        </Table>
      </Card>

      <div className="mt-4">
        <Button variant="ghost" onClick={() => router.back()}>← Back</Button>
      </div>
    </PageContainer>
  );
}
