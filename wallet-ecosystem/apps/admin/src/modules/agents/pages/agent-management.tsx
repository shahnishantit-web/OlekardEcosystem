'use client';

import { useCallback, useEffect, useState, Suspense } from 'react';
import { useRouter, useSearchParams } from 'next/navigation';
import Link from 'next/link';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';
import { AdminAppClient } from '@/lib/api/admin-app-client';
import type { AdminAgent } from '@/lib/api/admin-app-client';

// ---------------------------------------------------------------------------
// Status badge
// ---------------------------------------------------------------------------
function AgentStatusBadge({ status }: { status: string }) {
  const s = (status ?? '').toLowerCase();
  const variant =
    s === 'active'          ? 'success'  :
    s === 'pending_approval'? 'warning'  :
    s === 'suspended'       ? 'error'    : 'neutral';
  return <Badge label={(status || 'unknown').replace(/_/g, ' ').toUpperCase()} variant={variant} />;
}

// ---------------------------------------------------------------------------
// Filter state
// ---------------------------------------------------------------------------
interface Filters {
  search: string;
  status: string;
  region: string;
}

// ---------------------------------------------------------------------------
// Inner component (needs useSearchParams)
// ---------------------------------------------------------------------------
function AgentManagementInner() {
  const { authenticatedClient } = useAuth();
  const router        = useRouter();
  const searchParams  = useSearchParams();

  const [agents, setAgents]   = useState<AdminAgent[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError]     = useState<string | null>(null);
  const [totalCount, setTotalCount] = useState(0);
  const [page, setPage]       = useState(1);
  const pageSize = 20;

  const [filters, setFilters] = useState<Filters>({
    search: searchParams.get('search') ?? '',
    status: searchParams.get('status') ?? '',
    region: searchParams.get('region') ?? '',
  });

  const fetchAgents = useCallback(async (f: Filters, pg: number) => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const params: Record<string, string> = { page: String(pg), per_page: String(pageSize) };
      if (f.search) params.search = f.search;
      if (f.status) params.status = f.status;
      if (f.region) params.region = f.region;

      const client = new AdminAppClient(authenticatedClient);
      const res = await client.listAgents(params);
      const body = res.data as unknown as { data: AdminAgent[]; total?: number; meta?: { total?: number } };
      const items = Array.isArray(body) ? body : (body.data ?? []);
      const total = body.total ?? body.meta?.total ?? items.length;
      setAgents(items);
      setTotalCount(total);
    } catch {
      setError('Failed to load agents. Please try again.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient]);

  useEffect(() => { void fetchAgents(filters, page); }, [fetchAgents, filters, page]);

  const applyFilters = (updated: Filters) => {
    setFilters(updated);
    setPage(1);
    const qs = new URLSearchParams();
    if (updated.search) qs.set('search', updated.search);
    if (updated.status) qs.set('status', updated.status);
    if (updated.region) qs.set('region', updated.region);
    router.replace(`/agents?${qs.toString()}`);
  };

  const totalPages = Math.max(1, Math.ceil(totalCount / pageSize));

  const agentId = (a: AdminAgent) => a.agent_id ?? a.id;
  const agentStatus = (a: AdminAgent) => a.agent_status ?? a.status ?? 'unknown';
  const agentName = (a: AdminAgent) => a.full_name ?? '—';
  const agentCode = (a: AdminAgent) => a.business_name ?? a.phone_number ?? '—';
  const agentRegion = (a: AdminAgent) => a.location ?? '—';
  const agentJoined = (a: AdminAgent) => {
    const d = a.approved_at ?? a.created_at;
    return d ? new Date(d).toLocaleDateString() : '—';
  };
  const floatBalance = (a: AdminAgent) => {
    const minor = a.float_balance_minor_units ?? 0;
    const currency = a.float_currency ?? '';
    return `${currency} ${(minor / 100).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;
  };

  return (
    <PageContainer>
      <PageHeader title="Agents" />

      {/* Filters */}
      <Card className="mb-4 p-4">
        <div className="flex flex-wrap gap-3 items-end">
          <div className="flex-1 min-w-[200px]">
            <label htmlFor="agent-search" className="block text-xs font-medium text-text-secondary mb-1">
              Search
            </label>
            <input
              id="agent-search"
              type="search"
              value={filters.search}
              onChange={e => applyFilters({ ...filters, search: e.target.value })}
              placeholder="Name or business…"
              className="w-full rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
              aria-label="Search agents by name or code"
            />
          </div>

          <div>
            <label htmlFor="agent-status-filter" className="block text-xs font-medium text-text-secondary mb-1">
              Status
            </label>
            <select
              id="agent-status-filter"
              value={filters.status}
              onChange={e => applyFilters({ ...filters, status: e.target.value })}
              className="rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
              aria-label="Filter by status"
            >
              <option value="">All Statuses</option>
              <option value="pending_approval">Pending Approval</option>
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
              <option value="terminated">Terminated</option>
            </select>
          </div>

          <div>
            <label htmlFor="agent-region-filter" className="block text-xs font-medium text-text-secondary mb-1">
              Region
            </label>
            <input
              id="agent-region-filter"
              type="text"
              value={filters.region}
              onChange={e => applyFilters({ ...filters, region: e.target.value })}
              placeholder="Region…"
              className="rounded border border-ok-border px-3 py-2 text-sm focus:outline-none focus:border-ok-primary"
              aria-label="Filter by region"
            />
          </div>

          <Button
            variant="secondary"
            onClick={() => applyFilters({ search: '', status: '', region: '' })}
          >
            Clear Filters
          </Button>
        </div>
      </Card>

      {/* Table */}
      {loading ? (
        <Loading label="Loading agents…" />
      ) : error ? (
        <ErrorState message={error} onRetry={() => void fetchAgents(filters, page)} />
      ) : (
        <>
          <div className="overflow-x-auto rounded border border-ok-border">
            <table className="min-w-full" aria-label="Agents list">
              <thead className="bg-ok-surface">
                <tr>
                  {['Agent Name', 'Code / Business', 'Status', 'Float Balance', 'Region', 'Joined', ''].map(h => (
                    <th key={h} className="py-2 px-4 text-left text-xs font-semibold text-text-secondary uppercase tracking-wider">
                      {h}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {agents.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="py-8 text-center text-sm text-text-secondary">
                      No agents found.
                    </td>
                  </tr>
                ) : (
                  agents.map(agent => {
                    const id = agentId(agent);
                    return (
                      <tr key={id} className="border-t border-ok-border hover:bg-ok-hover-bg transition-colors">
                        <td className="py-2 px-4 text-sm font-medium text-text-primary">{agentName(agent)}</td>
                        <td className="py-2 px-4 text-sm text-text-secondary font-mono">{agentCode(agent)}</td>
                        <td className="py-2 px-4">
                          <AgentStatusBadge status={agentStatus(agent)} />
                        </td>
                        <td className="py-2 px-4 text-sm tabular-nums text-text-primary">{floatBalance(agent)}</td>
                        <td className="py-2 px-4 text-sm text-text-secondary">{agentRegion(agent)}</td>
                        <td className="py-2 px-4 text-sm text-text-secondary">{agentJoined(agent)}</td>
                        <td className="py-2 px-4 text-right">
                          <Link href={`/agents/${id}`}>
                            <Button variant="secondary" size="sm" aria-label={`View agent ${agentName(agent)}`}>View</Button>
                          </Link>
                        </td>
                      </tr>
                    );
                  })
                )}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex justify-between items-center mt-4 text-sm text-text-secondary">
              <span>{totalCount} agent{totalCount !== 1 ? 's' : ''}</span>
              <div className="flex gap-2">
                <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>
                  Previous
                </Button>
                <span className="py-1">Page {page} of {totalPages}</span>
                <Button variant="secondary" size="sm" disabled={page >= totalPages} onClick={() => setPage(p => p + 1)}>
                  Next
                </Button>
              </div>
            </div>
          )}
        </>
      )}
    </PageContainer>
  );
}

// ---------------------------------------------------------------------------
// Public export (wraps inner with Suspense for useSearchParams)
// ---------------------------------------------------------------------------
export function AgentManagementPage() {
  return (
    <Suspense fallback={<PageContainer><Loading label="Loading…" /></PageContainer>}>
      <AgentManagementInner />
    </Suspense>
  );
}
