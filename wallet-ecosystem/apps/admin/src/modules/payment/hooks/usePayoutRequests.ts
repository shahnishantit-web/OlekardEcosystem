'use client';

import { useState, useEffect, useCallback } from 'react';
import { PaymentApiClient } from '@/lib/api/payment';
import { useAuth } from '@/lib/auth/context';
import type { PayoutRequestSummary, PayoutFilterParams } from '@/lib/api/payment';

interface UsePayoutRequestsResult {
  payouts: PayoutRequestSummary[];
  isLoading: boolean;
  error: string | null;
  page: number;
  lastPage: number;
  setPage: (page: number) => void;
  refetch: () => void;
  approveReservation: (id: string) => Promise<void>;
  rejectReservation: (id: string, reason: string) => Promise<void>;
}

function generateIdempotencyKey(): string {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

export function usePayoutRequests(params: Omit<PayoutFilterParams, 'page' | 'per_page'> = {}): UsePayoutRequestsResult {
  const { authenticatedClient } = useAuth();
  const [payouts, setPayouts] = useState<PayoutRequestSummary[]>([]);
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
    client.listPayoutRequests({ ...params, page, per_page: 25 }).then((res) => {
      const paginated = res.data as unknown as { data: PayoutRequestSummary[]; meta: { last_page: number } };
      setPayouts(paginated.data ?? []);
      setLastPage(paginated.meta?.last_page ?? 1);
    }).catch(() => {
      setError('Failed to load payout requests.');
    }).finally(() => setIsLoading(false));
  }, [authenticatedClient, page, tick, params.status]);

  const approveReservation = useCallback(async (id: string) => {
    if (!authenticatedClient) return;
    const key = generateIdempotencyKey();
    const client = new PaymentApiClient(authenticatedClient);
    await client.approvePayoutReservation(id, key);
    setTick((t) => t + 1);
  }, [authenticatedClient]);

  const rejectReservation = useCallback(async (id: string, reason: string) => {
    if (!authenticatedClient) return;
    const client = new PaymentApiClient(authenticatedClient);
    await client.rejectPayoutReservation(id, { reason });
    setTick((t) => t + 1);
  }, [authenticatedClient]);

  const refetch = useCallback(() => setTick((t) => t + 1), []);

  return { payouts, isLoading, error, page, lastPage, setPage, refetch, approveReservation, rejectReservation };
}
