'use client';

import { useState, useEffect, useCallback } from 'react';
import { PaymentApiClient } from '@/lib/api/payment';
import { useAuth } from '@/lib/auth/context';
import type { RefundListItem } from '@/lib/api/payment';

interface UseRefundApprovalQueueResult {
  refunds: RefundListItem[];
  isLoading: boolean;
  error: string | null;
  refetch: () => void;
  approve: (id: string) => Promise<void>;
  reject: (id: string, reason: string) => Promise<void>;
}

function generateIdempotencyKey(): string {
  // UUID v4 fallback — UUID v7 preferred but not available universally in browser
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

export function useRefundApprovalQueue(): UseRefundApprovalQueueResult {
  const { authenticatedClient } = useAuth();
  const [refunds, setRefunds] = useState<RefundListItem[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (!authenticatedClient) return;
    setIsLoading(true);
    setError(null);
    const client = new PaymentApiClient(authenticatedClient);
    client.listRefundManagement({ status: 'pending_approval', per_page: 50 }).then((res) => {
      const page = res.data as unknown as { data: RefundListItem[] };
      setRefunds(page.data ?? []);
    }).catch(() => {
      setError('Failed to load refund approval queue.');
    }).finally(() => setIsLoading(false));
  }, [authenticatedClient, tick]);

  const approve = useCallback(async (id: string) => {
    if (!authenticatedClient) return;
    const key = generateIdempotencyKey();
    const client = new PaymentApiClient(authenticatedClient);
    await client.approveRefund(id, key);
    // Optimistic removal
    setRefunds((prev) => prev.filter((r) => r.id !== id));
  }, [authenticatedClient]);

  const reject = useCallback(async (id: string, reason: string) => {
    if (!authenticatedClient) return;
    const key = generateIdempotencyKey();
    const client = new PaymentApiClient(authenticatedClient);
    await client.rejectRefund(id, reason, key);
    setRefunds((prev) => prev.filter((r) => r.id !== id));
  }, [authenticatedClient]);

  const refetch = useCallback(() => setTick((t) => t + 1), []);

  return { refunds, isLoading, error, refetch, approve, reject };
}
