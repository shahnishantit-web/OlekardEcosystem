import { Badge } from '@/components/ui/Badge';
import type { BadgeVariant } from '@/components/ui/Badge';
import type { IntentStatus } from '@/lib/api/payment';

const variantMap: Record<IntentStatus, BadgeVariant> = {
  created:         'neutral',
  captured:        'info',
  fulfilled:       'success',
  failed:          'error',
  cancelled:       'neutral',
  pending_ledger:  'warning',
  ledger_failed:   'error',
};

const labelMap: Record<IntentStatus, string> = {
  created:         'Created',
  captured:        'Captured',
  fulfilled:       'Fulfilled',
  failed:          'Failed',
  cancelled:       'Cancelled',
  pending_ledger:  'Pending Ledger',
  ledger_failed:   'Ledger Failed',
};

interface Props {
  status: IntentStatus;
}

export function IntentStatusBadge({ status }: Props) {
  return <Badge label={labelMap[status]} variant={variantMap[status]} />;
}
