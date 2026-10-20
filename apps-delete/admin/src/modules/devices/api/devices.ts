import { generateCorrelationId } from '@/lib/utils/correlation';
import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
import type {
  DeviceListResponse,
  DeviceSecurityEventsResponse,
} from '@/src/modules/devices/types/device';

export interface ListDevicesParams {
  page?: number;
  per_page?: number;
  status?: string;
  platform?: string;
}

export async function getAdminDevices(
  client: AuthenticatedApiClient,
  userId: string,
  params?: ListDevicesParams,
): Promise<DeviceListResponse> {
  const query = new URLSearchParams();
  if (params?.page !== undefined) query.set('page', String(params.page));
  if (params?.per_page !== undefined) query.set('per_page', String(params.per_page));
  if (params?.status) query.set('status', params.status);
  if (params?.platform) query.set('platform', params.platform);
  const qs = query.toString();

  const res = await client.get<{ success: true; data: DeviceListResponse }>(
    `/v1/admin/users/${userId}/devices${qs ? `?${qs}` : ''}`,
  );
  return res.data.data;
}

export async function adminBlockDevice(
  client: AuthenticatedApiClient,
  deviceId: string,
  reason: string,
): Promise<void> {
  await client.post<{ success: true }>(
    `/v1/admin/devices/${deviceId}/block`,
    { reason },
    { idempotencyKey: `block-${deviceId}-${generateCorrelationId()}` },
  );
}

export async function adminUnblockDevice(
  client: AuthenticatedApiClient,
  deviceId: string,
  reason: string,
): Promise<void> {
  await client.post<{ success: true }>(
    `/v1/admin/devices/${deviceId}/unblock`,
    { reason },
    { idempotencyKey: `unblock-${deviceId}-${generateCorrelationId()}` },
  );
}

export async function adminRemoveDevice(
  client: AuthenticatedApiClient,
  deviceId: string,
): Promise<void> {
  await client.delete(
    `/v1/admin/devices/${deviceId}`,
    { idempotencyKey: `remove-${deviceId}-${generateCorrelationId()}` },
  );
}

export async function getDeviceSecurityEvents(
  client: AuthenticatedApiClient,
  deviceId: string,
  page = 1,
  perPage = 20,
): Promise<DeviceSecurityEventsResponse> {
  const res = await client.get<{ success: true; data: DeviceSecurityEventsResponse }>(
    `/v1/admin/devices/${deviceId}/security-events?page=${page}&per_page=${perPage}`,
  );
  return res.data.data;
}
