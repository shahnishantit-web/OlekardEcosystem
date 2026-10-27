import { Badge } from '@/components/ui/Badge';
import type { BadgeVariant } from '@/components/ui/Badge';
import type { PayoutRequestStatus } from '@/lib/api/payment';

const variantMap: Record<PayoutRequestStatus, BadgeVariant> = {
  awaiting_approval:    'warning',
  reservation_pending:  'warning',
  approved:             'info',
  rejected:             'error',
  executing:            'info',
  settled:              'success',
  failed:               'error',
};

const labelMap: Record<PayoutRequestStatus, string> = {
  awaiting_approval:    'Awaiting Approval',
  reservation_pending:  'Reservation Pending',
  approved:             'Approved',
  rejected:             'Rejected',
  executing:            'Executing',
  settled:              'Settled',
  failed:               'Failed',
};

interface Props {
  status: PayoutRequestStatus;
}

export function PayoutStatusBadge({ status }: Props) {
  return <Badge label={labelMap[status]} variant={variantMap[status]} />;
}
