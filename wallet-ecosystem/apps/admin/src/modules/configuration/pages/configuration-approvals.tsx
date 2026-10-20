'use client';

import { useCallback, useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Table } from '@/components/ui/Table';
import { useAuth } from '@/lib/auth/context';
import type { ChangeRequestItem } from '@/lib/api/configuration';
import { ConfigurationApiClient } from '@/lib/api/configuration';

function ageLabel(submittedAt: string): string {
  const diff = Date.now() - new Date(submittedAt).getTime();
  const hours = Math.floor(diff / 3_600_000);
  if (hours < 1) return 'Less than 1h';
  if (hours < 24) return `${hours}h ago`;
  return `${Math.floor(hours / 24)}d ago`;
}

function ageVariant(submittedAt: string): 'neutral' | 'warning' | 'error' {
  const hours = (Date.now() - new Date(submittedAt).getTime()) / 3_600_000;
  if (hours > 48) return 'error';
  if (hours > 24) return 'warning';
  return 'neutral';
}

export function ConfigurationApprovalsPage() {
  const { authenticatedClient, hasPermission } = useAuth();
  const router = useRouter();

  const canApprove = hasPermission('configuration.approve');

  const [requests, setRequests] = useState<ChangeRequestItem[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const fetchRequests = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const client = new ConfigurationApiClient(authenticatedClient);
      const res = await client.listChangeRequests({ status: 'pending_approval', per_page: 50 });
      setRequests(res.data.data);
    } catch {
      setError('Failed to load pending change requests.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient]);

  useEffect(() => {
    if (canApprove) void fetchRequests();
  }, [fetchRequests, canApprove]);

  if (!canApprove) {
    return (
      <PageContainer>
        <PageHeader title="Pending Approvals" />
        <Card className="p-6">
          <p role="alert" className="text-sm text-error">
            Access denied. You do not have permission to view configuration approvals.
          </p>
        </Card>
      </PageContainer>
    );
  }

  return (
    <PageContainer>
        <PageHeader title="Pending Approvals" />

      <Card>
        {loading && <Loading aria-label="Loading pending approvals" />}
        {error && <ErrorState message={error} onRetry={fetchRequests} />}
        {!loading && !error && (
          <Table aria-label="Pending configuration change requests">
            <thead>
              <tr>
                <th scope="col">Config Key</th>
                <th scope="col">Domain</th>
                <th scope="col">Maker</th>
                <th scope="col">Submitted</th>
                <th scope="col">Age</th>
              </tr>
            </thead>
            <tbody>
              {requests.length === 0 && (
                <tr>
                  <td colSpan={5} className="py-8 text-center text-muted-foreground">
                    No pending approvals.
                  </td>
                </tr>
              )}
              {requests.map((req) => (
                <tr
                  key={req.id}
                  className="cursor-pointer hover:bg-muted/50"
                  onClick={() => router.push(`/configuration/approvals/${req.id}`)}
                >
                  <td className="font-mono text-sm">{req.config_key}</td>
                  <td>
                    <Badge variant="neutral">{req.domain.replace('_', ' ')}</Badge>
                  </td>
                  <td className="text-sm text-muted-foreground">{req.maker_id.slice(0, 8)}…</td>
                  <td className="text-sm text-muted-foreground">
                    {new Date(req.submitted_at).toLocaleDateString()}
                  </td>
                  <td>
                    <Badge variant={ageVariant(req.submitted_at)} aria-label={`Age: ${ageLabel(req.submitted_at)}`}>
                      {ageLabel(req.submitted_at)}
                    </Badge>
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </PageContainer>
  );
}
