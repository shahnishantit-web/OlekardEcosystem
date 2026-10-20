'use client';

import { useCallback, useEffect, useId, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Empty } from '@/components/ui/Empty';
import { DeviceListTable } from '@/app/(admin)/devices/components/DeviceListTable';
import { useAuth } from '@/lib/auth/context';
import { getAdminDevices } from '@/src/modules/devices/api/devices';
import type { DeviceItem, DeviceListMeta } from '@/src/modules/devices/types/device';
import { isApiError } from '@/lib/utils/errors';
import type { ApiError } from '@/lib/utils/errors';

interface AdminDeviceListPageProps {
  userId: string;
}

export function AdminDeviceListPage({ userId }: AdminDeviceListPageProps) {
  const { authenticatedClient, isAuthenticated } = useAuth();
  const headingId = useId();

  const [devices, setDevices] = useState<DeviceItem[]>([]);
  const [meta, setMeta] = useState<DeviceListMeta>({ total: 0, per_page: 20, current_page: 1, last_page: 1 });
  const [loading, setLoading] = useState(false);
  const [fetchError, setFetchError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);

  const fetchDevices = useCallback(
    async (page: number) => {
      if (!authenticatedClient) return;
      setLoading(true);
      setFetchError(null);
      try {
        const data = await getAdminDevices(authenticatedClient, userId, { page, per_page: 20 });
        setDevices(data.data);
        setMeta(data.meta);
      } catch (err) {
        if (isApiError(err)) {
          const code = (err as ApiError).error.code;
          if (code === 'IDENTITY_022' || code === 'DEVICE_FORBIDDEN') {
            setForbidden(true);
            return;
          }
        }
        setFetchError('Failed to load devices. Please try again.');
      } finally {
        setLoading(false);
      }
    },
    [authenticatedClient, userId],
  );

  useEffect(() => {
    void fetchDevices(1);
  }, [fetchDevices]);

  if (!isAuthenticated) {
    return (
      <ErrorState
        title="Unauthorized"
        message="You must be signed in as an administrator to view devices."
      />
    );
  }

  if (forbidden) {
    return (
      <ErrorState
        title="Access denied"
        message="You do not have permission to manage this user's devices."
      />
    );
  }

  return (
    <main aria-labelledby={headingId} className="px-6 py-8 max-w-6xl mx-auto">
      <div className="mb-6 flex items-center justify-between gap-4">
        <div>
          <h1 id={headingId} className="text-xl font-semibold text-text-primary">
            Device Management
          </h1>
          <p className="mt-1 text-sm text-text-secondary">
            User: <code className="text-xs">{userId}</code>
          </p>
        </div>
      </div>

      {loading && <Loading label="Loading devices…" />}

      {!loading && fetchError && (
        <ErrorState
          message={fetchError}
          onRetry={() => void fetchDevices(meta.current_page)}
        />
      )}

      {!loading && !fetchError && devices.length === 0 && (
        <Empty
          title="No devices"
          description="This user has no registered devices."
        />
      )}

      {!loading && !fetchError && devices.length > 0 && (
        <>
          <DeviceListTable
            devices={devices}
            onRefresh={() => void fetchDevices(meta.current_page)}
          />

          {meta.last_page > 1 && (
            <nav
              aria-label="Device list pagination"
              className="mt-4 flex items-center gap-2"
            >
              <Button
                variant="secondary"
                onClick={() => void fetchDevices(meta.current_page - 1)}
                disabled={meta.current_page <= 1}
                aria-label="Previous page"
              >
                Previous
              </Button>
              <span className="text-sm text-text-secondary" aria-live="polite">
                Page {meta.current_page} of {meta.last_page}
              </span>
              <Button
                variant="secondary"
                onClick={() => void fetchDevices(meta.current_page + 1)}
                disabled={meta.current_page >= meta.last_page}
                aria-label="Next page"
              >
                Next
              </Button>
            </nav>
          )}
        </>
      )}
    </main>
  );
}
