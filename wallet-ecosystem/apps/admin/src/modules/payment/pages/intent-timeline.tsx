'use client';

import { useCallback } from 'react';
import Link from 'next/link';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { LoadingState } from '@/components/ui/LoadingState';
import { ErrorState } from '@/components/ui/ErrorState';
import { Card } from '@/components/ui/Card';
import { useAuth } from '@/lib/auth/context';
import { PaymentApiClient } from '@/lib/api/payment';
import { useIntentTimeline } from '../hooks/useIntentTimeline';
import { IntentStatusBadge } from '../components/IntentStatusBadge';
import { TimelineEvent } from '../components/TimelineEvent';
import { LedgerJournalCard } from '../components/LedgerJournalCard';
import { CurrencyAmount } from '../components/CurrencyAmount';
import type { IntentStatus } from '@/lib/api/payment';

interface Props {
  id: string;
}

export function IntentTimelinePage({ id }: Props) {
  const { authenticatedClient, hasPermission } = useAuth();
  const { timeline, isLoading, error, refetch } = useIntentTimeline(id);

  const canQueryStatus = hasPermission('payment.intents.admin');
  const canViewJournals = hasPermission('payment.history.read.platform');

  const handleQueryStatus = useCallback(async () => {
    if (!authenticatedClient) return;
    try {
      const api = new PaymentApiClient(authenticatedClient);
      await api.queryIntentStatus(id);
    } catch {
      // Fire-and-forget — user sees 202 or error via toast in parent
    }
  }, [authenticatedClient, id]);

  if (!hasPermission('payment.history.read.platform')) {
    return (
      <PageContainer>
        <div role="alert" className="py-16 text-center text-sm text-text-secondary">
          You do not have permission to view this payment intent.
        </div>
      </PageContainer>
    );
  }

  return (
    <PageContainer>
      <PageHeader
        title="Intent Timeline"
        actions={
          <Link href="/payments/finance/intents" className="text-sm text-blue-600 hover:underline">
            ← Back to list
          </Link>
        }
      />

      {isLoading && <LoadingState label="Loading intent timeline…" />}
      {!isLoading && error && <ErrorState message={error} onRetry={refetch} />}

      {!isLoading && !error && timeline && (
        <div className="space-y-6">
          {/* Intent summary */}
          <Card semanticLabel="Payment intent summary">
            <div className="flex flex-wrap items-start justify-between gap-4">
              <div>
                <p className="text-xs text-text-secondary font-mono">ID: {id}</p>
                <div className="mt-1 flex items-center gap-2">
                  <IntentStatusBadge status={timeline.intent.status as IntentStatus} />
                  {timeline.intent.has_pending_fulfilment && (
                    <span className="text-xs font-medium text-ok-warning-fg">⚠ Pending Fulfilment</span>
                  )}
                </div>
                <div className="mt-2 text-lg font-semibold text-text-primary">
                  <CurrencyAmount amountMinor={timeline.intent.amount_minor} currency={timeline.intent.currency_code} />
                </div>
              </div>

              {canQueryStatus && timeline.intent.status === 'captured' && (
                <button
                  type="button"
                  onClick={() => void handleQueryStatus()}
                  className="rounded-md border border-ok-border bg-white px-4 py-2 text-sm font-medium text-text-primary hover:bg-ok-hover-bg"
                >
                  Query Provider Status
                </button>
              )}
            </div>
          </Card>

          {/* Pending accounting banner */}
          {timeline.pending_accounting && (
            <div role="status" className="rounded-lg border border-ok-warning-border bg-ok-warning-bg px-4 py-3 text-sm text-ok-warning-fg">
              ⚠ This intent has a pending ledger accounting action.
            </div>
          )}

          {/* Chronological timeline */}
          <section aria-labelledby="timeline-heading">
            <h2 id="timeline-heading" className="mb-4 text-sm font-semibold uppercase tracking-wide text-text-secondary">
              Event Timeline
            </h2>
            <div>
              {/* Intent created */}
              <TimelineEvent
                type="intent"
                title="Intent created"
                timestamp={timeline.intent.created_at}
              />

              {/* Attempts */}
              {timeline.attempts.length === 0 ? (
                <TimelineEvent
                  type="attempt"
                  title="No attempts — intent created but not yet submitted"
                  timestamp={timeline.intent.created_at}
                  isLast={timeline.ledger_journals.length === 0 && !timeline.fulfilment}
                />
              ) : (
                timeline.attempts.map((attempt, idx) => (
                  <TimelineEvent
                    key={attempt.id}
                    type="attempt"
                    title={`Attempt ${attempt.attempt_number} — ${attempt.provider_code ?? 'unknown provider'}`}
                    timestamp={attempt.submitted_at}
                    isLast={idx === timeline.attempts.length - 1 && timeline.ledger_journals.length === 0 && !timeline.fulfilment}
                    detail={
                      <div className="space-y-1 text-xs text-text-secondary">
                        <div className="flex items-center gap-2">
                          <IntentStatusBadge status={attempt.status as IntentStatus} />
                          {attempt.provider_reference && (
                            <span className="font-mono">{attempt.provider_reference}</span>
                          )}
                        </div>
                        {attempt.failure_reason && (
                          <p className="text-ok-error-fg">Failure: {attempt.failure_reason}</p>
                        )}
                        {attempt.resolved_at && (
                          <p>Resolved: {new Date(attempt.resolved_at).toLocaleString()}</p>
                        )}
                      </div>
                    }
                  />
                ))
              )}

              {/* Ledger journals */}
              {canViewJournals && (
                <>
                  {timeline.ledger_journals.length === 0 ? (
                    <TimelineEvent
                      type="ledger"
                      title="No ledger journals yet"
                      timestamp={timeline.intent.updated_at}
                      isLast={!timeline.fulfilment}
                    />
                  ) : (
                    timeline.ledger_journals.map((journal, idx) => (
                      <TimelineEvent
                        key={journal.journal_id}
                        type="ledger"
                        title="Ledger posting"
                        timestamp={journal.posted_at}
                        isLast={idx === timeline.ledger_journals.length - 1 && !timeline.fulfilment}
                        detail={<LedgerJournalCard journal={journal} />}
                      />
                    ))
                  )}
                </>
              )}

              {/* Fulfilment */}
              {timeline.fulfilment && (
                <TimelineEvent
                  type="fulfilment"
                  title={`Fulfilment — ${timeline.fulfilment.status}`}
                  timestamp={timeline.fulfilment.dispatched_at ?? timeline.intent.updated_at}
                  isLast
                />
              )}
            </div>
          </section>
        </div>
      )}
    </PageContainer>
  );
}
