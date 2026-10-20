'use client';

import { useCallback, useEffect, useId, useState } from 'react';
import { useRouter } from 'next/navigation';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Table } from '@/components/ui/Table';
import { useAuth } from '@/lib/auth/context';
import type {
  ConfigurationKeyDetail,
  ConfigurationVersionItem,
  ChangeRequestStatus,
} from '@/lib/api/configuration';
import { ConfigurationApiClient } from '@/lib/api/configuration';
import { generateIdempotencyKey } from '@/lib/utils/idempotency';

interface Props {
  configKey: string;
}

function eventVariant(eventType: string): 'neutral' | 'success' | 'warning' | 'error' | 'info' {
  switch (eventType) {
    case 'activated': return 'success';
    case 'approved':  return 'info';
    case 'rejected':  return 'error';
    case 'withdrawn': return 'warning';
    case 'failed':    return 'error';
    default:          return 'neutral';
  }
}

export function ConfigurationKeyDetailPage({ configKey }: Props) {
  const { authenticatedClient, hasPermission } = useAuth();
  const router = useRouter();
  const headingId = useId();

  const canRead  = hasPermission('configuration.policy.view');
  const canWrite = hasPermission('configuration.policy.update');

  const [keyDetail, setKeyDetail] = useState<ConfigurationKeyDetail | null>(null);
  const [versions, setVersions] = useState<ConfigurationVersionItem[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [proposedValue, setProposedValue] = useState('');
  const [showDiff, setShowDiff] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [submitResult, setSubmitResult] = useState<{ status: ChangeRequestStatus; id: string } | null>(null);
  const [formError, setFormError] = useState<string | null>(null);

  const fetchData = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const client = new ConfigurationApiClient(authenticatedClient);
      const [keyRes, versionsRes] = await Promise.all([
        client.getKey(configKey),
        client.getKeyVersions(configKey, { per_page: 20 }),
      ]);
      setKeyDetail(keyRes.data);
      setVersions(versionsRes.data.data);
    } catch {
      setError('Failed to load configuration key details.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, configKey]);

  useEffect(() => {
    if (canRead) void fetchData();
  }, [canRead, fetchData]);

  const handleConfirmSubmit = useCallback(async () => {
    if (!authenticatedClient || !keyDetail) return;
    setSubmitting(true);
    setFormError(null);
    try {
      let parsedValue: unknown = proposedValue;
      if (keyDetail.value_type === 'number') {
        const n = Number(proposedValue);
        if (isNaN(n)) {
          setFormError('Please enter a valid number.');
          setSubmitting(false);
          return;
        }
        parsedValue = n;
      } else if (keyDetail.value_type === 'boolean') {
        if (proposedValue !== 'true' && proposedValue !== 'false') {
          setFormError('Please enter "true" or "false".');
          setSubmitting(false);
          return;
        }
        parsedValue = proposedValue === 'true';
      } else if (keyDetail.value_type === 'json') {
        try {
          parsedValue = JSON.parse(proposedValue);
        } catch {
          setFormError('Please enter valid JSON.');
          setSubmitting(false);
          return;
        }
      }
      const client = new ConfigurationApiClient(authenticatedClient);
      const res = await client.submitChangeRequest({
        config_key:      keyDetail.config_key,
        proposed_value:  parsedValue,
        idempotency_key: generateIdempotencyKey(),
      });
      setSubmitResult({ status: res.data.status, id: res.data.id });
      setShowDiff(false);
      setProposedValue('');
      void fetchData();
    } catch (err: unknown) {
      setFormError(err instanceof Error ? err.message : 'Failed to submit change.');
    } finally {
      setSubmitting(false);
    }
  }, [authenticatedClient, keyDetail, proposedValue, fetchData]);

  if (!canRead) {
    return (
      <main aria-labelledby={headingId}>
        <h1 id={headingId} className="font-mono text-xl font-semibold">{configKey}</h1>
        <Card className="mt-4 p-6">
          <p role="alert" className="text-sm text-error">
            Access denied. You do not have permission to view configuration keys.
          </p>
        </Card>
      </main>
    );
  }

  if (loading) return <Loading aria-label="Loading key details" />;
  if (error) return <ErrorState message={error} onRetry={fetchData} />;
  if (!keyDetail) return null;

  const hasPendingChange = !!keyDetail.pending_change_request_id;

  return (
    <main aria-labelledby={headingId}>
      <div className="mb-6 flex items-start justify-between">
        <div>
          <h1 id={headingId} className="font-mono text-xl font-semibold">
            {keyDetail.config_key}
          </h1>
          <p className="mt-1 text-sm text-muted-foreground">{keyDetail.description ?? 'No description'}</p>
        </div>
        <div className="flex items-center gap-2">
          <Badge variant="neutral">{keyDetail.domain.replace('_', ' ')}</Badge>
          {hasPendingChange && (
            <Badge variant="warning">Pending Change</Badge>
          )}
        </div>
      </div>

      {/* Current value */}
      <Card className="mb-6">
        <h2 className="mb-2 text-sm font-semibold text-muted-foreground">Current Value</h2>
        <pre className="overflow-auto rounded bg-muted p-3 text-xs">
          {keyDetail.current_value != null
            ? JSON.stringify(keyDetail.current_value, null, 2)
            : '(no active value)'}
        </pre>
        {hasPendingChange && (
          <div className="mt-3">
            <Button
              variant="outline"
              size="sm"
              onClick={() =>
                router.push(`/configuration/approvals/${keyDetail.pending_change_request_id}`)
              }
              aria-label="View pending change request"
            >
              View Pending Change Request →
            </Button>
          </div>
        )}
      </Card>

      {/* Submit result banner */}
      {submitResult && (
        <div role="alert" className="mb-4 rounded-md border border-success bg-success/10 p-3 text-sm text-success-foreground">
          {submitResult.status === 'pending_approval' ? (
            <>
              Change request submitted for approval.{' '}
              <button
                type="button"
                className="underline"
                onClick={() => router.push(`/configuration/approvals/${submitResult.id}`)}
              >
                View approval →
              </button>
            </>
          ) : (
            'Configuration value updated successfully.'
          )}
        </div>
      )}

      {/* Edit form — only when canWrite */}
      {canWrite && (
        <Card className="mb-6 p-4">
          <h2 className="mb-3 text-sm font-semibold">Edit Configuration Value</h2>
          {hasPendingChange ? (
            <p role="alert" className="text-sm text-muted-foreground">
              A change is pending approval for this key.{' '}
              <button
                type="button"
                className="underline"
                onClick={() =>
                  router.push(`/configuration/approvals/${keyDetail.pending_change_request_id}`)
                }
              >
                View pending change
              </button>
            </p>
          ) : (
            <>
              {formError && <p role="alert" className="mb-2 text-sm text-error">{formError}</p>}
              <label htmlFor="proposed-value" className="mb-1 block text-sm font-medium">
                Proposed Value
              </label>
              <textarea
                id="proposed-value"
                aria-label="Proposed value"
                value={proposedValue}
                onChange={(e) => setProposedValue(e.target.value)}
                rows={4}
                className="mb-3 w-full rounded-md border px-3 py-2 font-mono text-xs"
                placeholder={
                  keyDetail.value_type === 'json'
                    ? 'Enter valid JSON...'
                    : keyDetail.value_type === 'boolean'
                    ? 'true or false'
                    : `Enter ${keyDetail.value_type} value...`
                }
                disabled={showDiff}
              />
              {!showDiff && (
                <Button onClick={() => setShowDiff(true)} disabled={!proposedValue.trim()}>
                  Preview Change
                </Button>
              )}
            </>
          )}
        </Card>
      )}

      {/* Diff preview panel */}
      {showDiff && (
        <Card className="mb-6 p-4" aria-label="Change preview">
          <h2 className="mb-3 text-sm font-semibold">Change Preview</h2>
          <div className="grid gap-4 md:grid-cols-2">
            <div>
              <p className="mb-1 text-xs font-medium text-muted-foreground">Current Value</p>
              <pre className="overflow-auto rounded bg-muted p-3 text-xs">
                {keyDetail.current_value != null
                  ? JSON.stringify(keyDetail.current_value, null, 2)
                  : '(no active value)'}
              </pre>
            </div>
            <div>
              <p className="mb-1 text-xs font-medium text-muted-foreground">Proposed Value</p>
              <pre className="overflow-auto rounded bg-muted p-3 text-xs">{proposedValue}</pre>
            </div>
          </div>
          <div className="mt-4 flex gap-2">
            <Button onClick={() => void handleConfirmSubmit()} disabled={submitting}>
              {submitting ? 'Saving…' : 'Confirm Submit'}
            </Button>
            <Button variant="secondary" onClick={() => setShowDiff(false)} disabled={submitting}>
              Cancel Preview
            </Button>
          </div>
        </Card>
      )}

      {/* Version history */}
      <Card>
        <h2 className="mb-3 text-sm font-semibold">Version History</h2>
        {versions.length === 0 ? (
          <p className="py-4 text-center text-sm text-muted-foreground">No version history.</p>
        ) : (
          <Table aria-label={`Version history for ${configKey}`}>
            <thead>
              <tr>
                <th scope="col">Event</th>
                <th scope="col">Value</th>
                <th scope="col">Changed By</th>
                <th scope="col">Approved By</th>
                <th scope="col">Occurred At</th>
                <th scope="col">Effective From</th>
              </tr>
            </thead>
            <tbody>
              {versions.map((v) => (
                <tr key={v.id}>
                  <td>
                    <Badge variant={eventVariant(v.event_type)}>{v.event_type}</Badge>
                  </td>
                  <td className="max-w-[150px] truncate font-mono text-xs text-muted-foreground">
                    {v.value != null ? JSON.stringify(v.value) : '—'}
                  </td>
                  <td className="text-sm text-muted-foreground">
                    {v.actor_id ? v.actor_id.slice(0, 8) + '…' : 'System'}
                  </td>
                  <td className="text-sm text-muted-foreground">
                    {v.approved_by ? v.approved_by.slice(0, 8) + '…' : '—'}
                  </td>
                  <td className="text-sm text-muted-foreground">
                    {new Date(v.occurred_at).toLocaleString()}
                  </td>
                  <td className="text-sm text-muted-foreground">
                    {v.effective_from ? new Date(v.effective_from).toLocaleDateString() : '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
        )}
      </Card>
    </main>
  );
}
