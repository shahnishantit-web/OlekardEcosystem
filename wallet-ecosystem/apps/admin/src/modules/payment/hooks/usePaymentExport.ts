'use client';

import { useState, useCallback, useRef } from 'react';
import { PaymentApiClient } from '@/lib/api/payment';
import { useAuth } from '@/lib/auth/context';
import type { ExportRequest, ExportStatus } from '@/lib/api/payment';

type ExportPhaseStatus = 'idle' | 'pending' | 'processing' | 'downloadable' | 'error';

interface UsePaymentExportResult {
  exportStatus: ExportPhaseStatus;
  downloadUrl: string | null;
  isPolling: boolean;
  exportId: string | null;
  trigger: (payload: ExportRequest) => Promise<void>;
  reset: () => void;
}

const POLL_INTERVAL_MS = 5_000;
const MAX_POLLS        = 60;

export function usePaymentExport(): UsePaymentExportResult {
  const { authenticatedClient } = useAuth();
  const [exportStatus, setExportStatus] = useState<ExportPhaseStatus>('idle');
  const [downloadUrl, setDownloadUrl]   = useState<string | null>(null);
  const [isPolling, setIsPolling]        = useState(false);
  const [exportId, setExportId]          = useState<string | null>(null);
  const pollCount = useRef(0);
  const timerRef  = useRef<ReturnType<typeof setTimeout> | null>(null);

  const stopPolling = useCallback(() => {
    if (timerRef.current) { clearTimeout(timerRef.current); timerRef.current = null; }
    setIsPolling(false);
  }, []);

  const pollStatus = useCallback((id: string, client: PaymentApiClient) => {
    const poll = async () => {
      pollCount.current += 1;
      if (pollCount.current > MAX_POLLS) {
        stopPolling();
        setExportStatus('error');
        return;
      }
      try {
        const res = await client.getExportStatus(id);
        const status = res.data as unknown as ExportStatus;
        if (status.status === 'downloadable') {
          stopPolling();
          setExportStatus('downloadable');
          setDownloadUrl(status.download_url ?? null);
        } else if (status.status === 'error' || status.status === 'expired') {
          stopPolling();
          setExportStatus('error');
        } else {
          setExportStatus('processing');
          timerRef.current = setTimeout(() => { void poll(); }, POLL_INTERVAL_MS);
        }
      } catch {
        stopPolling();
        setExportStatus('error');
      }
    };
    void poll();
  }, [stopPolling]);

  const trigger = useCallback(async (payload: ExportRequest) => {
    if (!authenticatedClient || exportStatus !== 'idle') return;
    setExportStatus('pending');
    setIsPolling(true);
    pollCount.current = 0;
    try {
      const client = new PaymentApiClient(authenticatedClient);
      const res = await client.requestExport(payload);
      const result = res.data as unknown as { export_id: string; status: string };
      setExportId(result.export_id);
      pollStatus(result.export_id, client);
    } catch {
      setIsPolling(false);
      setExportStatus('error');
    }
  }, [authenticatedClient, exportStatus, pollStatus]);

  const reset = useCallback(() => {
    stopPolling();
    setExportStatus('idle');
    setDownloadUrl(null);
    setExportId(null);
    pollCount.current = 0;
  }, [stopPolling]);

  return { exportStatus, downloadUrl, isPolling, exportId, trigger, reset };
}
