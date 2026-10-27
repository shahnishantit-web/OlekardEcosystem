'use client';

import { useState, useEffect, useCallback } from 'react';
import { PaymentApiClient } from '@/lib/api/payment';
import { useAuth } from '@/lib/auth/context';
import type { RefundListItem, RefundListParams } from '@/lib/api/payment';

interface UseRefundManagementResult {
  refunds: RefundListItem[];
  isLoading: boolean;
  error: string | null;
  page: number;
  lastPage: number;
  setPage: (page: number) => void;
  refetch: () => void;
}

export function useRefundManagement(params: Omit<RefundListParams, 'page' | 'per_page'> = {}): UseRefundManagementResult {
  const { authenticatedClient } = useAuth();
  const [refunds, setRefunds] = useState<RefundListItem[]>([]);
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
    client.listRefundManagement({ ...params, page, per_page: 25 }).then((res) => {
      const paginated = res.data as unknown as { data: RefundListItem[]; meta: { last_page: number } };
      setRefunds(paginated.data ?? []);
      setLastPage(paginated.meta?.last_page ?? 1);
    }).catch(() => {
      setError('Failed to load refund list.');
    }).finally(() => setIsLoading(false));
  }, [authenticatedClient, page, tick, params.status, params.currency]);

  const refetch = useCallback(() => setTick((t) => t + 1), []);

  return { refunds, isLoading, error, page, lastPage, setPage, refetch };
}
