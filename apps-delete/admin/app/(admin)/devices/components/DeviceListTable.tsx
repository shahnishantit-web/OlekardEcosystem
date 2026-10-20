'use client';

import { useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Table } from '@/components/ui/Table';
import { Badge } from '@/components/ui/Badge';
import { Confirmation } from '@/components/ui/Confirmation';
import { Toast } from '@/components/ui/Toast';
import { BlockDeviceDialog } from './BlockDeviceDialog';
import { UnblockDeviceDialog } from './UnblockDeviceDialog';
import { DeviceSecurityEventsLog } from './DeviceSecurityEventsLog';
import { useAuth } from '@/lib/auth/context';
import { adminBlockDevice, adminUnblockDevice, adminRemoveDevice } from '@/src/modules/devices/api/devices';
import type { DeviceItem } from '@/src/modules/devices/types/device';
import { isApiError } from '@/lib/utils/errors';
import type { ApiError } from '@/lib/utils/errors';

const STATUS_VARIANT: Record<string, 'success' | 'warning' | 'error' | 'neutral'> = {
  trusted:              'success',
  verified:             'success',
  pending_verification: 'warning',
  blocked:              'error',
  revoked:              'neutral',
};

interface DeviceListTableProps {
  devices: DeviceItem[];
  onRefresh: () => void;
}

export function DeviceListTable({ devices, onRefresh }: DeviceListTableProps) {
  const { authenticatedClient } = useAuth();

  const [blockTarget, setBlockTarget] = useState<DeviceItem | null>(null);
  const [unblockTarget, setUnblockTarget] = useState<DeviceItem | null>(null);
  const [removeTarget, setRemoveTarget] = useState<DeviceItem | null>(null);
  const [eventsTarget, setEventsTarget] = useState<DeviceItem | null>(null);
  const [removing, setRemoving] = useState(false);
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  async function handleBlock(reason: string) {
    if (!blockTarget || !authenticatedClient) return;
    try {
      await adminBlockDevice(authenticatedClient, blockTarget.id, reason);
      setToastMessage(`Device "${blockTarget.device_name}" blocked.`);
      setBlockTarget(null);
      onRefresh();
    } catch (err) {
      const msg = isApiError(err) ? (err as ApiError).error.message : 'Failed to block device.';
      setToastMessage(`Error: ${msg}`);
      throw err;
    }
  }

  async function handleUnblock(reason: string) {
    if (!unblockTarget || !authenticatedClient) return;
    try {
      await adminUnblockDevice(authenticatedClient, unblockTarget.id, reason);
      setToastMessage(`Device "${unblockTarget.device_name}" unblocked.`);
      setUnblockTarget(null);
      onRefresh();
    } catch (err) {
      const msg = isApiError(err) ? (err as ApiError).error.message : 'Failed to unblock device.';
      setToastMessage(`Error: ${msg}`);
      throw err;
    }
  }

  async function handleRemove() {
    if (!removeTarget || !authenticatedClient) return;
    setRemoving(true);
    try {
      await adminRemoveDevice(authenticatedClient, removeTarget.id);
      setToastMessage(`Device "${removeTarget.device_name}" removed.`);
      onRefresh();
    } catch (err) {
      const msg = isApiError(err) ? (err as ApiError).error.message : 'Failed to remove device.';
      setToastMessage(`Error: ${msg}`);
    } finally {
      setRemoving(false);
      setRemoveTarget(null);
    }
  }

  const columns = [
    {
      key: 'device_name',
      header: 'Device',
      render: (row: DeviceItem) => (
        <div>
          <span className="text-sm font-medium text-text-primary">{row.device_name}</span>
          <span className="block text-xs text-text-secondary">{row.platform}</span>
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (row: DeviceItem) => (
        <Badge
          label={row.status.replace(/_/g, ' ')}
          variant={STATUS_VARIANT[row.status] ?? 'neutral'}
        />
      ),
    },
    {
      key: 'last_seen',
      header: 'Last seen',
      render: (row: DeviceItem) => (
        row.last_seen_at ? (
          <time dateTime={row.last_seen_at} className="text-sm text-text-secondary">
            {new Date(row.last_seen_at).toLocaleString()}
          </time>
        ) : <span className="text-sm text-text-secondary">Never</span>
      ),
    },
    {
      key: 'created_at',
      header: 'Registered',
      render: (row: DeviceItem) => (
        <time dateTime={row.created_at} className="text-sm text-text-secondary">
          {new Date(row.created_at).toLocaleString()}
        </time>
      ),
    },
    {
      key: 'actions',
      header: 'Actions',
      render: (row: DeviceItem) => (
        <div className="flex items-center gap-2 flex-wrap">
          <Button
            variant="secondary"
            className="text-xs px-2"
            style={{ minHeight: '28px' }}
            onClick={() => setEventsTarget(row)}
            aria-label={`View security events for ${row.device_name}`}
          >
            Events
          </Button>
          {row.status === 'blocked' ? (
            <Button
              variant="secondary"
              className="text-xs px-2"
              style={{ minHeight: '28px' }}
              onClick={() => setUnblockTarget(row)}
              aria-label={`Unblock ${row.device_name}`}
            >
              Unblock
            </Button>
          ) : row.status !== 'revoked' && (
            <Button
              variant="danger"
              className="text-xs px-2"
              style={{ minHeight: '28px' }}
              onClick={() => setBlockTarget(row)}
              aria-label={`Block ${row.device_name}`}
            >
              Block
            </Button>
          )}
          {row.status !== 'revoked' && (
            <Button
              variant="danger"
              className="text-xs px-2"
              style={{ minHeight: '28px' }}
              onClick={() => setRemoveTarget(row)}
              aria-label={`Remove ${row.device_name}`}
            >
              Remove
            </Button>
          )}
        </div>
      ),
    },
  ];

  return (
    <>
      <Table
        caption="User devices"
        columns={columns}
        data={devices}
        getRowKey={(row) => row.id}
      />

      {eventsTarget && (
        <div className="mt-4 border border-border-subtle rounded-lg p-4">
          <div className="flex items-center justify-between mb-2">
            <h3 className="text-sm font-semibold text-text-primary">
              Security events — {eventsTarget.device_name}
            </h3>
            <Button
              variant="secondary"
              className="text-xs px-2"
              style={{ minHeight: '28px' }}
              onClick={() => setEventsTarget(null)}
            >
              Close
            </Button>
          </div>
          <DeviceSecurityEventsLog deviceId={eventsTarget.id} />
        </div>
      )}

      <BlockDeviceDialog
        open={blockTarget !== null}
        deviceName={blockTarget?.device_name ?? ''}
        onClose={() => setBlockTarget(null)}
        onConfirm={handleBlock}
      />

      <UnblockDeviceDialog
        open={unblockTarget !== null}
        deviceName={unblockTarget?.device_name ?? ''}
        onClose={() => setUnblockTarget(null)}
        onConfirm={handleUnblock}
      />

      <Confirmation
        open={removeTarget !== null}
        onClose={() => setRemoveTarget(null)}
        onConfirm={() => { void handleRemove(); }}
        title="Remove device"
        message={`This will permanently revoke "${removeTarget?.device_name ?? 'this device'}" and terminate all its active sessions. This cannot be undone.`}
        destructive
      />

      {toastMessage && (
        <Toast
          message={toastMessage}
          onDismiss={() => setToastMessage(null)}
        />
      )}

      {removing && (
        <div aria-live="polite" className="sr-only">Removing device…</div>
      )}
    </>
  );
}
