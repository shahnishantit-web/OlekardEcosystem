import { Badge } from '@/components/ui/Badge';
import type { BadgeVariant } from '@/components/ui/Badge';
import type { RefundStatus } from '@/lib/api/payment';

const variantMap: Record<RefundStatus, BadgeVariant> = {
  pending_approval: 'warning',
  approved:         'info',
  rejected:         'error',
  processing:       'info',
  completed:        'success',
  failed:           'error',
};

const labelMap: Record<RefundStatus, string> = {
  pending_approval: 'Pending Approval',
  approved:         'Approved',
  rejected:         'Rejected',
  processing:       'Processing',
  completed:        'Completed',
  failed:           'Failed',
};

interface Props {
  status: RefundStatus;
}

export function RefundStatusBadge({ status }: Props) {
  return <Badge label={labelMap[status]} variant={variantMap[status]} />;
}
