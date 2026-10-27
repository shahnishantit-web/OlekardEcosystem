'use client';

import { useState, useEffect, useCallback } from 'react';
import { PaymentApiClient } from '@/lib/api/payment';
import { useAuth } from '@/lib/auth/context';
import type { DashboardSummary } from '@/lib/api/payment';

interface UseDashboardSummaryResult {
  summary: DashboardSummary | null;
  isLoading: boolean;
  error: string | null;
  fetchedAt: Date | null;
  refetch: () => void;
}

export function useDashboardSummary(): UseDashboardSummaryResult {
  const { authenticatedClient } = useAuth();
  const [summary, setSummary] = useState<DashboardSummary | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fetchedAt, setFetchedAt] = useState<Date | null>(null);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (!authenticatedClient) return;
    const client = new PaymentApiClient(authenticatedClient);
    setIsLoading(true);
    setError(null);
    client.getDashboardSummary().then((res) => {
      setSummary(res.data as unknown as DashboardSummary);
      setFetchedAt(new Date());
    }).catch(() => {
      setError('Failed to load finance dashboard data.');
    }).finally(() => setIsLoading(false));
  }, [authenticatedClient, tick]);

  const refetch = useCallback(() => setTick((t) => t + 1), []);

  return { summary, isLoading, error, fetchedAt, refetch };
}
