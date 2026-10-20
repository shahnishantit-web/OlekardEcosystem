import type { Metadata } from 'next';
import { AdminDeviceListPage } from '@/src/modules/devices/pages/device-list';

export const metadata: Metadata = {
  title: 'Device Management — OleKard Admin',
};

interface DeviceListRouteProps {
  params: { userId: string };
}

export default function DeviceListRoute({ params }: DeviceListRouteProps) {
  return <AdminDeviceListPage userId={params.userId} />;
}
