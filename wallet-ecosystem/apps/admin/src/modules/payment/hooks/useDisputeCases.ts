'use client';

import { useState, useEffect, useCallback } from 'react';
import { PaymentApiClient } from '@/lib/api/payment';
import { useAuth } from '@/lib/auth/context';
import type { DisputeCaseSummary, DisputeFilterParams, HoldPayload } from '@/lib/api/payment';

interface UseDisputeCasesResult {
  cases: DisputeCaseSummary[];
  isLoading: boolean;
  error: string | null;
  page: number;
  lastPage: number;
  setPage: (page: number) => void;
  refetch: () => void;
  authoriseHold: (id: string, payload: HoldPayload) => Promise<void>;
}

function generateIdempotencyKey(): string {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

export function useDisputeCases(params: Omit<DisputeFilterParams, 'page' | 'per_page'> = {}): UseDisputeCasesResult {
  const { authenticatedClient } = useAuth();
  const [cases, setCases] = useState<DisputeCaseSummary[]>([]);
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
    client.listDisputeCases({ ...params, page, per_page: 25 }).then((res) => {
      const paginated = res.data as unknown as { data: DisputeCaseSummary[]; meta: { last_page: number } };
      setCases(paginated.data ?? []);
      setLastPage(paginated.meta?.last_page ?? 1);
    }).catch(() => setError('Failed to load dispute cases.'))
      .finally(() => setIsLoading(false));
  }, [authenticatedClient, page, tick, params.status, params.case_type, params.requires_finance_review]);

  const authoriseHold = useCallback(async (id: string, payload: HoldPayload) => {
    if (!authenticatedClient) return;
    const key = generateIdempotencyKey();
    const client = new PaymentApiClient(authenticatedClient);
    await client.authoriseDisputeHold(id, payload, key);
    setTick((t) => t + 1);
  }, [authenticatedClient]);

  const refetch = useCallback(() => setTick((t) => t + 1), []);

  return { cases, isLoading, error, page, lastPage, setPage, refetch, authoriseHold };
}
