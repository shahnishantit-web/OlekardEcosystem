'use client';

import { Badge } from '@/components/ui/Badge';
import type { AccountLifecycleStatus } from '@/lib/api/payment';

const VARIANT_MAP: Record<AccountLifecycleStatus, 'success' | 'warning' | 'neutral' | 'info' | 'error'> = {
  active: 'success',
  suspended: 'warning',
  draft: 'neutral',
  pending_approval: 'info',
  retired: 'neutral',
  rejected: 'error',
};

const LABEL_MAP: Record<AccountLifecycleStatus, string> = {
  active: 'Active',
  suspended: 'Suspended',
  draft: 'Draft',
  pending_approval: 'Pending Approval',
  retired: 'Retired',
  rejected: 'Rejected',
};

interface Props {
  status: AccountLifecycleStatus;
  className?: string;
}

export function AccountLifecycleStatusBadge({ status, className }: Props) {
  return (
    <Badge
      label={LABEL_MAP[status] ?? status}
      variant={VARIANT_MAP[status] ?? 'neutral'}
      {...(className !== undefined ? { className } : {})}
    />
  );
}
