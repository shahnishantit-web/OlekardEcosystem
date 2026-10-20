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
import type {
  ConfigurationDomain,
  ConfigurationKeyItem,
} from '@/lib/api/configuration';
import { ConfigurationApiClient } from '@/lib/api/configuration';

type DomainTabId = 'all' | ConfigurationDomain;

const DOMAIN_TABS: { id: DomainTabId; label: string }[] = [
  { id: 'all', label: 'All' },
  { id: 'financial_policy', label: 'Financial Policy' },
  { id: 'operational_policy', label: 'Operational Policy' },
  { id: 'geographic', label: 'Geographic' },
];

export function ConfigurationListPage() {
  const { authenticatedClient, hasPermission } = useAuth();
  const router = useRouter();

  const canRead = hasPermission('configuration.policy.view');

  const [keys, setKeys] = useState<ConfigurationKeyItem[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [activeTab, setActiveTab] = useState<DomainTabId>('all');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);

  const fetchKeys = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const client = new ConfigurationApiClient(authenticatedClient);
      const listParams: { domain?: ConfigurationDomain; search?: string; per_page: number; page: number } = {
        per_page: 50,
        page,
      };
      if (activeTab !== 'all') listParams.domain = activeTab as ConfigurationDomain;
      if (search) listParams.search = search;
      const res = await client.listKeys(listParams);
      setKeys(res.data.data);
      setLastPage(res.data.last_page);
    } catch {
      setError('Failed to load configuration keys.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, activeTab, search, page]);

  useEffect(() => {
    if (canRead) void fetchKeys();
  }, [fetchKeys, canRead]);

  if (!canRead) {
    return (
      <PageContainer>
        <PageHeader title="Configuration Management" />
        <Card className="p-6">
          <p role="alert" className="text-sm text-error">
            Access denied. You do not have permission to view configuration.
          </p>
        </Card>
      </PageContainer>
    );
  }

  return (
    <PageContainer>
        <PageHeader title="Configuration Management" />

      {/* Domain tabs */}
      <div className="mb-4 flex gap-2 border-b" role="tablist" aria-label="Configuration domain filter">
        {DOMAIN_TABS.map((tab) => (
          <button
            key={tab.id}
            role="tab"
            aria-selected={activeTab === tab.id}
            onClick={() => { setActiveTab(tab.id); setPage(1); }}
            className={`px-4 py-2 text-sm font-medium transition-colors ${
              activeTab === tab.id
                ? 'border-b-2 border-primary text-primary'
                : 'text-muted-foreground hover:text-foreground'
            }`}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* Search */}
      <div className="mb-4">
        <input
          type="search"
          placeholder="Search configuration keys..."
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1); }}
          className="w-full rounded-md border px-3 py-2 text-sm"
          aria-label="Search configuration keys"
        />
      </div>

      <Card>
        {loading && <Loading aria-label="Loading configuration keys" />}
        {error && <ErrorState message={error} onRetry={fetchKeys} />}
        {!loading && !error && (
          <Table aria-label="Configuration keys">
            <thead>
              <tr>
                <th scope="col">Key</th>
                <th scope="col">Domain</th>
                <th scope="col">Current Value</th>
                <th scope="col">Last Modified</th>
                <th scope="col">Pending</th>
              </tr>
            </thead>
            <tbody>
              {keys.length === 0 && (
                <tr>
                  <td colSpan={5} className="py-8 text-center text-muted-foreground">
                    No configuration keys found.
                  </td>
                </tr>
              )}
              {keys.map((key) => (
                <tr
                  key={key.config_key}
                  className="cursor-pointer hover:bg-muted/50"
                  onClick={() =>
                    router.push(
                      `/configuration/${encodeURIComponent(key.domain)}/${encodeURIComponent(key.config_key)}`
                    )
                  }
                >
                  <td className="font-mono text-sm">{key.config_key}</td>
                  <td>
                    <Badge variant="neutral">{key.domain.replace('_', ' ')}</Badge>
                  </td>
                  <td className="max-w-[200px] truncate font-mono text-xs text-muted-foreground">
                    {key.current_value != null
                      ? JSON.stringify(key.current_value)
                      : <span className="italic">—</span>}
                  </td>
                  <td className="text-sm text-muted-foreground">
                    {key.updated_at
                      ? new Date(key.updated_at).toLocaleDateString()
                      : '—'}
                  </td>
                  <td>
                    <Badge variant={key.has_pending_change ? 'warning' : 'neutral'}>
                      {key.has_pending_change ? 'Pending' : 'None'}
                    </Badge>
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
      {!loading && !error && lastPage > 1 && (
        <nav aria-label="Configuration pagination" className="mt-4 flex items-center gap-4">
          <Button variant="secondary" disabled={page <= 1} onClick={() => setPage(p => p - 1)}>Previous</Button>
          <span>Page {page} of {lastPage}</span>
          <Button variant="secondary" disabled={page >= lastPage} onClick={() => setPage(p => p + 1)}>Next</Button>
        </nav>
      )}
    </PageContainer>
  );
}
