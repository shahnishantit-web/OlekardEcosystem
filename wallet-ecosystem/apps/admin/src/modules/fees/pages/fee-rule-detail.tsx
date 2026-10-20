'use client';

import { useCallback, useEffect, useState } from 'react';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Card } from '@/components/ui/Card';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Dialog } from '@/components/ui/Dialog';
import { Toast } from '@/components/ui/Toast';
import { useAuth } from '@/lib/auth/context';
import {
  getFeeRule,
  deactivateFeeRule,
  calculateFee,
  type FeeRule,
  type FeeCalculationResult,
} from '@/src/modules/fees/api/fees';
import type { BadgeVariant } from '@/components/ui/Badge';

// ---------------------------------------------------------------------------
// Sub-components
// ---------------------------------------------------------------------------

function FeeRuleStatusBadge({ status }: { status: FeeRule['status'] }) {
  const variant: BadgeVariant =
    status === 'active'      ? 'success' :
    status === 'deactivated' ? 'error'   :
    status === 'expired'     ? 'warning' : 'neutral';
  return <Badge label={status.toUpperCase()} variant={variant} />;
}

function DetailField({
  label,
  value,
  mono = false,
}: {
  label: string;
  value: React.ReactNode;
  mono?: boolean;
}) {
  return (
    <div>
      <dt className="text-xs font-medium text-text-secondary uppercase tracking-wide">{label}</dt>
      <dd className={`mt-0.5 text-sm text-text-primary${mono ? ' font-mono' : ''}`}>
        {value ?? '—'}
      </dd>
    </div>
  );
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

export function FeeRuleDetailPage({ ruleId }: { ruleId: string }) {
  const { authenticatedClient, hasPermission } = useAuth();

  const [rule, setRule] = useState<FeeRule | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [toast, setToast] = useState<{ message: string; kind: 'success' | 'error' } | null>(null);

  // Deactivation
  const [deactivateDialogOpen, setDeactivateDialogOpen] = useState(false);
  const [deactivating, setDeactivating] = useState(false);

  // Simulation
  const [simOpen, setSimOpen] = useState(false);
  const [simAmountDisplay, setSimAmountDisplay] = useState('');
  const [simLoading, setSimLoading] = useState(false);
  const [simResult, setSimResult] = useState<FeeCalculationResult | null>(null);
  const [simError, setSimError] = useState<string | null>(null);

  const fetchRule = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const fetched = await getFeeRule(authenticatedClient, ruleId);
      setRule(fetched);
    } catch (err) {
      const httpStatus = (err as { status?: number })?.status;
      const code = (err as { error?: { code?: string } })?.error?.code;
      if (httpStatus === 404 || code === 'NOT_FOUND') {
        setError('Fee rule not found.');
      } else {
        setError('Failed to load fee rule. Please try again.');
      }
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient, ruleId]);

  useEffect(() => { void fetchRule(); }, [fetchRule]);

  const handleDeactivate = async () => {
    if (!authenticatedClient || !rule) return;
    setDeactivating(true);
    try {
      await deactivateFeeRule(authenticatedClient, ruleId);
      setRule((prev) => (prev ? { ...prev, status: 'deactivated' } : prev));
      setToast({ message: 'Fee rule deactivated successfully.', kind: 'success' });
    } catch {
      setToast({ message: 'Failed to deactivate rule. Please try again.', kind: 'error' });
    } finally {
      setDeactivating(false);
      setDeactivateDialogOpen(false);
    }
  };

  const handleCalculate = async () => {
    if (!authenticatedClient || !rule) return;
    const parsed = parseFloat(simAmountDisplay);
    if (isNaN(parsed) || parsed <= 0) {
      setSimError('Enter a valid amount greater than 0.');
      return;
    }
    const amountMinor = Math.round(parsed * 100);
    if (amountMinor > 999_999_999) {
      setSimError('Amount too large. Maximum is BWP 9,999,999.99.');
      return;
    }
    setSimError(null);
    setSimResult(null);
    setSimLoading(true);
    try {
      const result = await calculateFee(authenticatedClient, {
        transaction_type: rule.transaction_type,
        amount_minor: amountMinor,
        currency: rule.currency,
        subject_type: 'global',
      });
      setSimResult(result);
    } catch {
      setSimError('Fee calculation failed. Please try again.');
    } finally {
      setSimLoading(false);
    }
  };

  if (loading) {
    return (
      <PageContainer>
        <Loading label="Loading fee rule…" />
      </PageContainer>
    );
  }

  if (error) {
    return (
      <PageContainer>
        <ErrorState message={error} onRetry={() => void fetchRule()} />
      </PageContainer>
    );
  }

  if (!rule) return null;

  const feeAmountDisplay =
    rule.fee_type === 'percentage'
      ? `${rule.rate}%`
      : `BWP ${((rule.amount_minor ?? 0) / 100).toFixed(2)}`;

  const canDeactivate = rule.status === 'active' && hasPermission('fees.rules.delete');

  return (
    <PageContainer>
      <PageHeader
        title={rule.transaction_type.replace(/_/g, ' ').toUpperCase()}
        breadcrumbs={[
          { label: 'Fee Rules', href: '/fees/rules' },
          { label: rule.transaction_type.replace(/_/g, ' ') },
        ]}
        actions={
          canDeactivate ? (
            <Button
              variant="danger"
              aria-label="Deactivate this fee rule"
              onClick={() => setDeactivateDialogOpen(true)}
            >
              Deactivate
            </Button>
          ) : undefined
        }
      />

      {toast && (
        <Toast
          message={toast.message}
          onDismiss={() => setToast(null)}
        />
      )}

      <Card>
        <dl className="grid grid-cols-2 gap-x-6 gap-y-4 p-4 sm:grid-cols-3">
          <DetailField label="ID"               value={rule.id}             mono />
          <DetailField label="Transaction Type" value={rule.transaction_type.replace(/_/g, ' ')} />
          <DetailField label="Fee Type"         value={rule.fee_type} />
          <DetailField label="Fee Amount"       value={feeAmountDisplay} />
          <DetailField
            label="Min Fee"
            value={rule.min_fee_minor != null ? `BWP ${(rule.min_fee_minor / 100).toFixed(2)}` : null}
          />
          <DetailField
            label="Max Fee"
            value={rule.max_fee_minor != null ? `BWP ${(rule.max_fee_minor / 100).toFixed(2)}` : null}
          />
          <DetailField label="Currency"         value={rule.currency} />
          <DetailField label="Subject Type"     value={rule.subject_type} />
          <DetailField label="Subject ID"       value={rule.subject_id}    mono />
          <DetailField
            label="Valid From"
            value={new Date(rule.valid_from).toLocaleString()}
          />
          <DetailField
            label="Valid To"
            value={rule.valid_to ? new Date(rule.valid_to).toLocaleString() : 'Open-ended'}
          />
          <DetailField label="Status"           value={<FeeRuleStatusBadge status={rule.status} />} />
          <DetailField label="Created At"       value={new Date(rule.created_at).toLocaleString()} />
          <DetailField label="Created By"       value={rule.created_by}    mono />
          <DetailField label="Updated At"       value={new Date(rule.updated_at).toLocaleString()} />
        </dl>
      </Card>

      {rule.status !== 'active' && (
        <p className="mt-3 text-sm text-text-secondary">
          This rule has been {rule.status} and is no longer applied to transactions.
        </p>
      )}

      {/* Simulation panel */}
      <div className="mt-6">
        <Button
          variant="secondary"
          aria-label="Toggle fee impact simulation panel"
          onClick={() => {
            setSimOpen((o) => !o);
            setSimResult(null);
            setSimError(null);
          }}
        >
          ▶ Simulate Fee Impact
        </Button>

        {simOpen && (
          <Card className="mt-3">
            <div className="p-4 space-y-4">
              <h3 className="text-sm font-semibold text-text-primary">Fee Impact Simulation</h3>
              <p className="text-xs text-text-secondary">
                Simulates the fee using the currently active rule for{' '}
                <strong>{rule.transaction_type.replace(/_/g, ' ')}</strong>.
                No transaction is created.
              </p>
              <div>
                <label
                  htmlFor="sim-amount"
                  className="block text-sm font-medium text-text-primary mb-1"
                >
                  Transaction Amount (BWP)
                </label>
                <input
                  id="sim-amount"
                  type="number"
                  step="0.01"
                  min="0.01"
                  value={simAmountDisplay}
                  onChange={(e) => {
                    setSimAmountDisplay(e.target.value);
                    setSimError(null);
                    setSimResult(null);
                  }}
                  className="w-full max-w-xs rounded border border-border px-3 py-2 text-sm bg-surface"
                  placeholder="100.00"
                  aria-describedby={simError ? 'sim-error' : undefined}
                />
                {simError && (
                  <p id="sim-error" className="mt-1 text-sm text-error" role="alert">
                    {simError}
                  </p>
                )}
              </div>

              <Button
                variant="primary"
                onClick={() => void handleCalculate()}
                disabled={simLoading}
                aria-label="Calculate fee for the entered transaction amount"
              >
                {simLoading ? 'Calculating…' : 'Calculate Fee'}
              </Button>

              {simResult && !simLoading && (
                <div className="mt-2 rounded border border-ok-border bg-ok-surface p-4">
                  {!simResult.has_applicable_rule ? (
                    <p className="text-sm text-text-secondary">
                      No fee rule found — this transaction type currently incurs no fee.
                    </p>
                  ) : (
                    <dl className="space-y-2">
                      <div className="flex justify-between text-sm">
                        <dt className="text-text-secondary">Fee Amount</dt>
                        <dd className="font-medium text-text-primary">
                          BWP {(simResult.fee_amount_minor / 100).toFixed(2)}
                          {simResult.min_fee_applied && (
                            <span className="ml-2 text-xs text-text-secondary">(minimum fee applied)</span>
                          )}
                          {simResult.max_fee_applied && (
                            <span className="ml-2 text-xs text-text-secondary">(maximum fee applied)</span>
                          )}
                        </dd>
                      </div>
                      <div className="flex justify-between text-sm">
                        <dt className="text-text-secondary">Fee Type</dt>
                        <dd className="text-text-primary">{simResult.fee_type}</dd>
                      </div>
                      <div className="flex justify-between text-sm font-semibold">
                        <dt className="text-text-secondary">Net Amount</dt>
                        <dd className="text-text-primary">
                          BWP {((parseFloat(simAmountDisplay) * 100 - simResult.fee_amount_minor) / 100).toFixed(2)}
                        </dd>
                      </div>
                    </dl>
                  )}
                </div>
              )}
            </div>
          </Card>
        )}
      </div>

      {/* Deactivation confirmation dialog */}
      <Dialog
        open={deactivateDialogOpen}
        onClose={() => setDeactivateDialogOpen(false)}
        title="Deactivate Fee Rule"
      >
        <p className="text-sm text-text-primary mb-4">
          This rule will no longer apply to transactions. This action cannot be undone.
          To restore fees for this transaction type, create a new rule.
        </p>
        <div className="flex gap-3 mt-2">
          <Button
            variant="secondary"
            onClick={() => setDeactivateDialogOpen(false)}
            disabled={deactivating}
          >
            Cancel
          </Button>
          <Button
            variant="danger"
            onClick={() => void handleDeactivate()}
            disabled={deactivating}
            aria-label="Confirm deactivation of this fee rule"
          >
            {deactivating ? 'Deactivating…' : 'Confirm Deactivation'}
          </Button>
        </div>
      </Dialog>
    </PageContainer>
  );
}
