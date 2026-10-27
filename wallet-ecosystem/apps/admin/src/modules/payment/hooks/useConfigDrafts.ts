'use client';

import { useState, useEffect, useCallback } from 'react';
import { PaymentApiClient } from '@/lib/api/payment';
import { useAuth } from '@/lib/auth/context';
import type { ConfigDraft, ConfigDraftStatus, PaginatedResponse } from '@/lib/api/payment';

interface UseConfigDraftsResult {
  drafts: ConfigDraft[];
  loading: boolean;
  error: string | null;
  refetch: () => void;
}

export function useConfigDrafts(
  providerId: string,
  params: { status?: ConfigDraftStatus } = {},
): UseConfigDraftsResult {
  const { authenticatedClient } = useAuth();
  const [drafts, setDrafts] = useState<ConfigDraft[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (!authenticatedClient || !providerId) return;
    const client = new PaymentApiClient(authenticatedClient);
    setLoading(true);
    client.listConfigDrafts(providerId, params)
      .then((res) => {
        setDrafts((res.data as PaginatedResponse<ConfigDraft>).data);
      })
      .catch(() => setError('Failed to load drafts'))
      .finally(() => setLoading(false));
  }, [authenticatedClient, providerId, params.status, tick]);

  const refetch = useCallback(() => setTick((t) => t + 1), []);
  return { drafts, loading, error, refetch };
}
