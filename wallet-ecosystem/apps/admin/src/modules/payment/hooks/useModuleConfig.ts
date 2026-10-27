'use client';

import { useState, useEffect, useCallback } from 'react';
import { PaymentApiClient } from '@/lib/api/payment';
import { useAuth } from '@/lib/auth/context';
import type { ModuleConfig, UpdateModuleConfigPayload } from '@/lib/api/payment';

interface UseModuleConfigResult {
  modules: ModuleConfig[];
  loading: boolean;
  error: string | null;
  updating: boolean;
  updateResult: { type: 'requires_approval'; draft_id?: string } | { type: 'error' } | null;
  updateModules: (payload: UpdateModuleConfigPayload) => Promise<void>;
  refetch: () => void;
}

export function useModuleConfig(): UseModuleConfigResult {
  const { authenticatedClient } = useAuth();
  const [modules, setModules] = useState<ModuleConfig[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [updating, setUpdating] = useState(false);
  const [updateResult, setUpdateResult] = useState<{ type: 'requires_approval'; draft_id?: string } | { type: 'error' } | null>(null);
  const [tick, setTick] = useState(0);

  useEffect(() => {
    if (!authenticatedClient) return;
    const client = new PaymentApiClient(authenticatedClient);
    setLoading(true);
    client.getModuleConfigs()
      .then((res) => {
        setModules((res.data as { data: ModuleConfig[] }).data);
      })
      .catch(() => setError('Failed to load modules'))
      .finally(() => setLoading(false));
  }, [authenticatedClient, tick]);

  const updateModules = useCallback(async (payload: UpdateModuleConfigPayload) => {
    if (!authenticatedClient) return;
    const client = new PaymentApiClient(authenticatedClient);
    setUpdating(true);
    setUpdateResult(null);
    try {
      const res = await client.updateModuleConfigs(payload);
      const data = res.data as { requires_approval: true; draft_id?: string };
      const dr = data.draft_id;
      setUpdateResult({
        type: 'requires_approval',
        ...(dr !== undefined ? { draft_id: dr } : {}),
      });
    } catch {
      setUpdateResult({ type: 'error' });
    } finally {
      setUpdating(false);
    }
  }, [authenticatedClient]);

  const refetch = useCallback(() => setTick((t) => t + 1), []);
  return { modules, loading, error, updating, updateResult, updateModules, refetch };
}
