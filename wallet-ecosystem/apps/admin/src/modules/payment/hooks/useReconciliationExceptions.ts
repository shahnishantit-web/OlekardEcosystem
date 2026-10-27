'use client';

import { useState, useEffect, useCallback } from 'react';
import { PaymentApiClient } from '@/lib/api/payment';
import { useAuth } from '@/lib/auth/context';
import type { ReconciliationException, ReconciliationExceptionStatus, ReconciliationExceptionType } from '@/lib/api/payment';

interface FilterParams {
  status?: ReconciliationExceptionStatus;
  type?: ReconciliationExceptionType;
  provider?: string;
  date_from?: string;
  date_to?: string;
  page?: number;
  per_page?: number;
}

interface UseReconciliationExceptionsResult {
  exceptions: ReconciliationException[];
  isLoading: boolean;
  error: string | null;
  page: number;
  lastPage: number;
  setPage: (page: number) => void;
  refetch: () => void;
  updateException: (id: string, update: { status?: ReconciliationExceptionStatus; notes?: string }) => Promise<void>;
}

export function useReconciliationExceptions(
  params: Omit<FilterParams, 'page' | 'per_page'> = { status: 'open' },
): UseReconciliationExceptionsResult {
  const { authenticatedClient } = useAuth();
  const [exceptions, setExceptions] = useState<ReconciliationException[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (!authenticatedClient) return;
    setIsLoading(true);
    setError(null);
    const client = new PaymentApiClient(authenticatedClient);
    client.listReconciliationExceptions({ ...params, page, per_page: 25 }).then((res) => {
      const paginated = res.data as unknown as { data: ReconciliationException[]; meta: { last_page: number } };
      setExceptions(paginated.data ?? []);
      setLastPage(paginated.meta?.last_page ?? 1);
    }).catch(() => setError('Failed to load reconciliation exceptions.'))
      .finally(() => setIsLoading(false));
  }, [authenticatedClient, page, tick, params.status, params.type]);

  const updateException = useCallback(async (id: string, update: { status?: ReconciliationExceptionStatus; notes?: string }) => {
    if (!authenticatedClient) return;
    const client = new PaymentApiClient(authenticatedClient);
    await client.updateExceptionStatus(id, update);
    setTick((t) => t + 1);
  }, [authenticatedClient]);

  const refetch = useCallback(() => setTick((t) => t + 1), []);

  return { exceptions, isLoading, error, page, lastPage, setPage, refetch, updateException };
}
