export type DeviceStatus = 'pending_verification' | 'verified' | 'trusted' | 'blocked' | 'revoked';
export type DevicePlatform = 'ios' | 'android' | 'web' | 'other';

export interface DeviceItem {
  id: string;
  user_id: string;
  device_name: string;
  platform: DevicePlatform;
  fingerprint_hash: string;
  status: DeviceStatus;
  blocked_at: string | null;
  blocked_reason: string | null;
  trusted_at: string | null;
  last_seen_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface DeviceSecurityEvent {
  id: string;
  device_id: string;
  event_type: string;
  risk_signals: Record<string, unknown> | null;
  resolution: string | null;
  resolved_at: string | null;
  acknowledgment_expires_at: string | null;
  created_at: string;
}

export interface DeviceListMeta {
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
}

export interface DeviceListResponse {
  data: DeviceItem[];
  meta: DeviceListMeta;
}

export interface DeviceSecurityEventsResponse {
  data: DeviceSecurityEvent[];
  meta: DeviceListMeta;
}
