import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';

export interface NotificationPreferences {
  user_id: string;
  push_enabled_categories: string[];
  sms_enabled_categories: string[];
  email_enabled_categories: string[];
  marketing_opted_in: boolean;
  updated_at: string | null;
}

export interface UpdateNotificationPrefsPayload {
  push_enabled_categories?: string[];
  sms_enabled_categories?: string[];
  email_enabled_categories?: string[];
  marketing_opted_in?: boolean;
}

export async function getNotificationPrefs(
  client: AuthenticatedApiClient,
): Promise<NotificationPreferences> {
  const res = await client.get<NotificationPreferences>('/v1/notifications/preferences');
  return res.data;
}

export async function updateNotificationPrefs(
  client: AuthenticatedApiClient,
  payload: UpdateNotificationPrefsPayload,
): Promise<NotificationPreferences> {
  const res = await client.put<NotificationPreferences>(
    '/v1/notifications/preferences',
    payload,
  );
  return res.data;
}
