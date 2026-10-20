import { generateCorrelationId } from '@/lib/utils/correlation';
import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';

export type ConfigDataType = 'integer' | 'boolean' | 'string' | 'decimal';

export interface ConfigEntry {
  key: string;
  value: string;
  default_value: string;
  description: string;
  data_type: ConfigDataType;
  min?: number;
  max?: number;
  allowed_values?: string[];
  last_updated_at: string;
  last_updated_by: string;
  is_sensitive: boolean;
}

export interface ConfigListResponse {
  configuration: ConfigEntry[];
  total: number;
}

export interface ConfigUpdateResult {
  key: string;
  old_value: string;
  new_value: string;
  updated_at: string;
  updated_by: string;
}

export const CONFIG_SECTIONS: Record<string, string[]> = {
  Authentication: ['auth.pin.length', 'auth.pin.reject_sequential', 'auth.pin.reject_repeated', 'auth.pin.reject_common'],
  'Account Lockout': ['auth.lockout.max_failed_attempts', 'auth.lockout.initial_duration', 'auth.lockout.progressive_enabled'],
  Session: ['auth.session.inactivity_ttl_admin', 'auth.session.inactivity_ttl_customer', 'auth.session.inactivity_ttl_agent'],
  OTP: ['auth.otp.ttl', 'auth.otp.max_attempts', 'auth.otp.resend_cooldown', 'auth.otp.max_sends_per_hour', 'auth.otp.provider'],
  MFA: ['auth.mfa.admin.totp_enabled', 'auth.mfa.admin.fido2_enabled', 'auth.mfa.admin.sms_enabled'],
  'Refresh Token': ['auth.refresh_token.ttl', 'auth.refresh_token.rotation_enabled', 'auth.refresh_token.reuse_detection_enabled'],
  'Step-Up Authentication': ['auth.step_up_threshold', 'auth.step_up_threshold_currency'],
  Biometric: ['auth.biometric.enabled'],
  'Audit Security': ['security.ip_audit.hash_algorithm'],
};

export async function listSecurityConfig(client: AuthenticatedApiClient): Promise<ConfigListResponse> {
  const res = await client.get<ConfigListResponse>('/v1/admin/platform/configuration/security');
  return res.data;
}

export async function updateSecurityConfigKey(
  client: AuthenticatedApiClient,
  key: string,
  value: string,
  version: string,
  reason?: string,
): Promise<ConfigUpdateResult> {
  const res = await client.put<ConfigUpdateResult>(
    `/v1/admin/platform/configuration/security/${encodeURIComponent(key)}`,
    { value, version, ...(reason ? { reason } : {}) },
    { idempotencyKey: `config-${key}-${generateCorrelationId()}` },
  );
  return res.data;
}
