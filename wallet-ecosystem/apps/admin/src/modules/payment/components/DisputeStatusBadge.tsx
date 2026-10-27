import { Badge } from '@/components/ui/Badge';
import type { BadgeVariant } from '@/components/ui/Badge';
import type { DisputeStatus } from '@/lib/api/payment';

const variantMap: Record<DisputeStatus, BadgeVariant> = {
  open:             'error',
  on_hold:          'warning',
  under_review:     'info',
  won:              'success',
  lost:             'error',
  closed_reversed:  'neutral',
};

const labelMap: Record<DisputeStatus, string> = {
  open:             'Open',
  on_hold:          'On Hold',
  under_review:     'Under Review',
  won:              'Won',
  lost:             'Lost',
  closed_reversed:  'Closed/Reversed',
};

interface Props {
  status: DisputeStatus;
}

export function DisputeStatusBadge({ status }: Props) {
  return <Badge label={labelMap[status]} variant={variantMap[status]} />;
}
