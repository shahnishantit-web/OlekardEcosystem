'use client';

import { Badge } from '@/components/ui/Badge';
import type { ProviderEnvironment } from '@/lib/api/payment';

// T067: Provider status badge — Active (green), Suspended (orange), Sandbox (blue), Mock/Dev (red)

interface Props {
  environment: ProviderEnvironment;
  is_enabled: boolean;
  is_suspended: boolean;
}

type BadgeVariant = 'neutral' | 'success' | 'warning' | 'error' | 'info';

export function ProviderStatusBadge({ environment, is_enabled, is_suspended }: Props) {
  if (is_suspended) {
    return <Badge label="SUSPENDED" variant="warning" />;
  }
  if (environment === 'mock') {
    return <Badge label="MOCK/DEV" variant="error" />;
  }
  if (environment === 'sandbox') {
    return <Badge label="SANDBOX" variant="info" />;
  }
  if (is_enabled) {
    return <Badge label="ACTIVE" variant="success" />;
  }
  return <Badge label="DISABLED" variant="neutral" />;
}

export function providerStatusVariant(
  environment: ProviderEnvironment,
  is_enabled: boolean,
  is_suspended: boolean,
): BadgeVariant {
  if (is_suspended) return 'warning';
  if (environment === 'mock') return 'error';
  if (environment === 'sandbox') return 'info';
  if (is_enabled) return 'success';
  return 'neutral';
}
