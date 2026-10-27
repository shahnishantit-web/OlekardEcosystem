'use client';

import { useState, useEffect, useCallback } from 'react';
import { PaymentApiClient } from '@/lib/api/payment';
import { useAuth } from '@/lib/auth/context';
import type { PaymentProviderAccountDetail, UpdateAccountPayload } from '@/lib/api/payment';
import { isHighRiskUpdateResponse as checkHighRisk } from '@/src/modules/payment/api/providerConfig';

type UpdateResult =
  | { type: 'saved'; data: PaymentProviderAccountDetail }
  | { type: 'requires_approval'; draft_id?: string; high_risk_fields: string[] }
  | { type: 'conflict' }
  | { type: 'error'; message: string };

interface UseProviderAccountResult {
  account: PaymentProviderAccountDetail | null;
  loading: boolean;
  error: string | null;
  updating: boolean;
  updateResult: UpdateResult | null;
  updateAccount: (payload: UpdateAccountPayload) => Promise<void>;
  refetch: () => void;
}

export function useProviderAccount(providerId: string, accountId: string): UseProviderAccountResult {
  const { authenticatedClient } = useAuth();
  const [account, setAccount] = useState<PaymentProviderAccountDetail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [updating, setUpdating] = useState(false);
  const [updateResult, setUpdateResult] = useState<UpdateResult | null>(null);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (!authenticatedClient || !providerId || !accountId) return;
    const client = new PaymentApiClient(authenticatedClient);
    setLoading(true);
    client.getProviderAccount(providerId, accountId)
      .then((res) => {
        setAccount((res.data as { data: PaymentProviderAccountDetail }).data);
      })
      .catch(() => setError('Failed to load account'))
      .finally(() => setLoading(false));
  }, [authenticatedClient, providerId, accountId, tick]);

  const updateAccount = useCallback(async (payload: UpdateAccountPayload) => {
    if (!authenticatedClient) return;
    const client = new PaymentApiClient(authenticatedClient);
    setUpdating(true);
    setUpdateResult(null);
    try {
      const res = await client.updateProviderAccount(providerId, accountId, payload);
      const data = res.data as ({ data: PaymentProviderAccountDetail } | { requires_approval: true; draft_id?: string; high_risk_fields: string[] });
      if (checkHighRisk(data)) {
        const dr = data.draft_id;
        setUpdateResult({
          type: 'requires_approval',
          ...(dr !== undefined ? { draft_id: dr } : {}),
          high_risk_fields: data.high_risk_fields,
        });
      } else {
        const saved = (data as { data: PaymentProviderAccountDetail }).data;
        setAccount(saved);
        setUpdateResult({ type: 'saved', data: saved });
      }
    } catch (err: unknown) {
      const isConflict =
        err !== null &&
        typeof err === 'object' &&
        'error' in err &&
        (err as { error: { code?: string } }).error?.code === 'CONFIGURATION_VERSION_CONFLICT';
      if (isConflict) {
        setUpdateResult({ type: 'conflict' });
      } else {
        setUpdateResult({ type: 'error', message: 'Update failed' });
      }
    } finally {
      setUpdating(false);
    }
  }, [authenticatedClient, providerId, accountId]);

  const refetch = useCallback(() => setTick((t) => t + 1), []);

  return { account, loading, error, updating, updateResult, updateAccount, refetch };
}
