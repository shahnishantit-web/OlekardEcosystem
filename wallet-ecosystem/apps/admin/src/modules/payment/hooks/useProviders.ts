'use client';

import { useState, useEffect, useCallback } from 'react';
import { PaymentApiClient } from '@/lib/api/payment';
import { useAuth } from '@/lib/auth/context';
import type { PaymentProvider } from '@/lib/api/payment';

interface UseProvidersResult {
  providers: PaymentProvider[];
  loading: boolean;
  error: string | null;
  refetch: () => void;
}

export function useProviders(
  params: { statusFilter?: string; adapterFilter?: string } = {},
): UseProvidersResult {
  const { authenticatedClient } = useAuth();
  const [providers, setProviders] = useState<PaymentProvider[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (!authenticatedClient) return;
    const client = new PaymentApiClient(authenticatedClient);
    setLoading(true);
    client.listProviders().then((res) => {
      let list = res.data as unknown as PaymentProvider[];
      if (params.statusFilter) {
        const sf = params.statusFilter;
        list = list.filter((p) => sf === 'suspended' ? p.is_suspended : !p.is_suspended);
      }
      if (params.adapterFilter) {
        const af = params.adapterFilter;
        list = list.filter((p) => p.adapter_identifier === af);
      }
      setProviders(list);
      setError(null);
    }).catch(() => setError('Failed to load providers')).finally(() => setLoading(false));
  }, [authenticatedClient, tick, params.statusFilter, params.adapterFilter]);

  const refetch = useCallback(() => setTick((t) => t + 1), []);

  return { providers, loading, error, refetch };
}
