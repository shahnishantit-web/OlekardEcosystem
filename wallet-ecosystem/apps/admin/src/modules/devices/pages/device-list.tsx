'use client';

import { useCallback, useEffect, useId, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Loading } from '@/components/ui/Loading';
import { ErrorState } from '@/components/ui/ErrorState';
import { Empty } from '@/components/ui/Empty';
import { DeviceListTable } from '@/app/(admin)/devices/components/DeviceListTable';
import { useAuth } from '@/lib/auth/context';
import { getAdminDevices } from '@/src/modules/devices/api/devices';
import type { DeviceItem, DeviceListMeta } from '@/src/modules/devices/types/device';
import { isApiError } from '@/lib/utils/errors';
import type { ApiError } from '@/lib/utils/errors';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';

interface AdminDeviceListPageProps {
  userId: string;
  /** When true, shows "My Devices" heading instead of "User: {userId}". */
  selfService?: boolean;
  /** The device ID belonging to the current session, used to highlight the row. */
  currentDeviceId?: string | undefined;
}

export function AdminDeviceListPage({
  userId,
  selfService = false,
  currentDeviceId,
}: AdminDeviceListPageProps) {
  const { authenticatedClient, isAuthenticated } = useAuth();
  const headingId = useId();

  const [devices, setDevices] = useState<DeviceItem[]>([]);
  const [meta, setMeta] = useState<DeviceListMeta>({ total: 0, per_page: 20, current_page: 1, last_page: 1 });
  const [loading, setLoading] = useState(false);
  const [fetchError, setFetchError] = useState<string | null>(null);
  const [forbidden, setForbidden] = useState(false);
  const [search, setSearch] = useState('');

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

  // Client-side search filter by device name or platform
  const filteredDevices = search.trim()
    ? devices.filter(
        (d) =>
          d.device_name.toLowerCase().includes(search.toLowerCase()) ||
          d.platform.toLowerCase().includes(search.toLowerCase()),
      )
    : devices;

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
    <PageContainer>
      {selfService ? (
        <PageHeader
          title="My Devices"
          description="Registered devices associated with your admin account."
        />
      ) : (
        <div id={headingId} className="mb-6">
          <h1 className="text-xl font-semibold text-text-primary">Device Management</h1>
          <p className="mt-1 text-sm text-text-secondary">
            User: <code className="text-xs">{userId}</code>
          </p>
        </div>
      )}

      <div className="mb-4 max-w-sm">
        <Input
          label="Search devices"
          placeholder="Filter by name or platform…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          aria-label="Search devices"
        />
      </div>

      {loading && <Loading label="Loading devices…" />}

      {!loading && fetchError && (
        <ErrorState
          message={fetchError}
          onRetry={() => void fetchDevices(meta.current_page)}
        />
      )}

      {!loading && !fetchError && filteredDevices.length === 0 && (
        <Empty
          title="No devices"
          description={search ? 'No devices match your search.' : 'This user has no registered devices.'}
        />
      )}

      {!loading && !fetchError && filteredDevices.length > 0 && (
        <>
          <DeviceListTable
            devices={filteredDevices}
            currentDeviceId={currentDeviceId}
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
    </PageContainer>
  );
}
