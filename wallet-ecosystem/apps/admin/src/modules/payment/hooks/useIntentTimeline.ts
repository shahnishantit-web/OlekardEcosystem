'use client';

import { useState, useEffect, useCallback } from 'react';
import { PaymentApiClient } from '@/lib/api/payment';
import { useAuth } from '@/lib/auth/context';
import type { PaymentIntentTimeline } from '@/lib/api/payment';

interface UseIntentTimelineResult {
  timeline: PaymentIntentTimeline | null;
  isLoading: boolean;
  error: string | null;
  refetch: () => void;
}

const cache = new Map<string, PaymentIntentTimeline>();

export function useIntentTimeline(id: string): UseIntentTimelineResult {
  const { authenticatedClient } = useAuth();
  const [timeline, setTimeline] = useState<PaymentIntentTimeline | null>(cache.get(id) ?? null);
  const [isLoading, setIsLoading] = useState(!cache.has(id));
  const [error, setError] = useState<string | null>(null);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (!authenticatedClient || !id) return;
    setIsLoading(true);
    setError(null);
    const client = new PaymentApiClient(authenticatedClient);
    client.getIntentTimeline(id).then((res) => {
      const data = res.data as unknown as PaymentIntentTimeline;
      cache.set(id, data);
      setTimeline(data);
    }).catch(() => {
      setError('Failed to load intent timeline.');
    }).finally(() => setIsLoading(false));
  }, [authenticatedClient, id, tick]);

  const refetch = useCallback(() => {
    cache.delete(id);
    setTick((t) => t + 1);
  }, [id]);

  return { timeline, isLoading, error, refetch };
}
