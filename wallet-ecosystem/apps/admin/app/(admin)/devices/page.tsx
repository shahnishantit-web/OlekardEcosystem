'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth/context';
import { AdminDeviceListPage } from '@/src/modules/devices/pages/device-list';
import { listOwnSessions } from '@/src/modules/auth/api/sessions';
import { Loading } from '@/components/ui/Loading';

/**
 * Self-service device management page for the authenticated admin.
 * Loads the current session's device_id to highlight the active device row.
 * US-P1-004-001, US-P1-004-002, US-P1-004-003.
 */
export default function DevicesPage() {
  const { user, authenticatedClient, isAuthenticated } = useAuth();
  const [currentDeviceId, setCurrentDeviceId] = useState<string | undefined>(undefined);

  useEffect(() => {
    if (!authenticatedClient) return;

    const currentSessionId =
      document.cookie.match(/(?:^|;\s*)admin_sid=([^;]*)/)
        ?.[1] ?? '';

    if (!currentSessionId) return;

    void listOwnSessions(authenticatedClient).then((sessions) => {
      const current = sessions.find((s) => s.id === currentSessionId);
      if (current?.device_id) {
        setCurrentDeviceId(current.device_id);
      }
    });
  }, [authenticatedClient]);

  if (!isAuthenticated || !user?.id) {
    return <Loading label="Loading devices…" />;
  }

  return (
    <AdminDeviceListPage
      userId={user.id}
      selfService
      currentDeviceId={currentDeviceId}
    />
  );
}
