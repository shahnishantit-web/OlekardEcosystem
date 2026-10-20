'use client';

import { useEffect, useState } from 'react';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { useAuth } from '@/lib/auth/context';
import { AdminAppClient } from '@/lib/api/admin-app-client';
import type {
  ComplianceDashboard,
  ChecklistItem,
  OpenComplianceItem,
  RetentionDeadline,
} from '@/lib/api/admin-app-client';

function checklistVariant(status: ChecklistItem['status']): 'success' | 'warning' | 'error' {
  switch (status) {
    case 'ok':       return 'success';
    case 'warning':  return 'warning';
    case 'critical': return 'error';
  }
}

function urgencyVariant(urgency: OpenComplianceItem['urgency'] | RetentionDeadline['urgency']): 'success' | 'warning' | 'error' {
  switch (urgency) {
    case 'low':    return 'success';
    case 'medium': return 'warning';
    case 'high':   return 'error';
  }
}

export function ComplianceStatusPage() {
  const { authenticatedClient, hasPermission } = useAuth();

  const canRead = hasPermission('compliance.read');

  const [dashboard, setDashboard] = useState<ComplianceDashboard | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!canRead || !authenticatedClient) return;
    setLoading(true);
    setError(null);
    new AdminAppClient(authenticatedClient)
      .getComplianceDashboard()
      .then(res => setDashboard(res.data))
      .catch(err => setError(err instanceof Error ? err.message : 'Failed to load compliance dashboard.'))
      .finally(() => setLoading(false));
  }, [canRead, authenticatedClient]);

  if (!canRead) {
    return (
      <PageContainer>
        <PageHeader title="Compliance Dashboard" />
        <Card className="p-6">
          <p role="alert" className="text-sm text-error">
            Access denied. You do not have permission to view the compliance dashboard.
          </p>
        </Card>
      </PageContainer>
    );
  }

  return (
    <PageContainer>
      <PageHeader title="Compliance Dashboard" />

      {loading && <Loading />}
      {!loading && error && <ErrorState message={error} onRetry={() => window.location.reload()} />}

      {!loading && !error && dashboard && (
        <>
          {/* Regulatory Checklist */}
          <Card className="mb-4 p-4">
            <h2 className="text-base font-semibold mb-3">Regulatory Checklist</h2>
            {dashboard.regulatory_checklist.length === 0 ? (
              <p className="text-sm text-text-secondary">No checklist items configured.</p>
            ) : (
              <ul className="space-y-2" aria-label="Regulatory checklist">
                {dashboard.regulatory_checklist.map((item, i) => (
                  <li key={i} className="flex items-start gap-3 text-sm">
                    <Badge variant={checklistVariant(item.status)} label={item.status.toUpperCase()} />
                    <div>
                      <p className="font-medium">{item.label}</p>
                      {item.detail && <p className="text-text-secondary text-xs mt-0.5">{item.detail}</p>}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          {/* Open Compliance Items */}
          <Card className="mb-4 p-4">
            <h2 className="text-base font-semibold mb-3">Open Compliance Items</h2>
            {dashboard.open_items.length === 0 ? (
              <p className="text-sm text-text-secondary">No open compliance items.</p>
            ) : (
              <ul className="space-y-2" aria-label="Open compliance items">
                {dashboard.open_items.map(item => (
                  <li key={item.id} className="flex items-start gap-3 text-sm border-b border-ok-border pb-2 last:border-0 last:pb-0">
                    <Badge variant={urgencyVariant(item.urgency)} label={item.urgency.toUpperCase()} />
                    <div className="flex-1">
                      <p className="font-medium">{item.description}</p>
                      <p className="text-xs text-text-secondary mt-0.5">
                        Category: {item.category}
                        {item.due_date && ` · Due: ${new Date(item.due_date).toLocaleDateString()}`}
                      </p>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Card>

          {/* Upcoming Retention Deadlines */}
          <Card className="p-4">
            <h2 className="text-base font-semibold mb-3">Upcoming Retention Deadlines</h2>
            {dashboard.retention_deadlines.length === 0 ? (
              <p className="text-sm text-text-secondary">No upcoming retention deadlines.</p>
            ) : (
              <ul className="space-y-2" aria-label="Upcoming retention deadlines">
                {[...dashboard.retention_deadlines]
                  .sort((a, b) => a.deadline_at.localeCompare(b.deadline_at))
                  .map(item => (
                    <li key={item.policy_id} className="flex items-center gap-3 text-sm">
                      <Badge variant={urgencyVariant(item.urgency)} label={item.urgency.toUpperCase()} />
                      <span className="font-mono text-xs flex-1">{item.data_category}</span>
                      <span className="text-xs text-text-secondary">
                        {new Date(item.deadline_at).toLocaleDateString()}
                      </span>
                    </li>
                  ))}
              </ul>
            )}
          </Card>
        </>
      )}
    </PageContainer>
  );
}
