import { Badge } from './Badge';
import type { BadgeVariant } from './Badge';

const STATUS_VARIANT_MAP: Record<string, BadgeVariant> = {
  // User / account
  active:     'success',
  inactive:   'neutral',
  suspended:  'error',
  blocked:    'error',
  pending:    'warning',
  verified:   'success',
  unverified: 'neutral',
  locked:     'error',

  // Transaction
  completed:  'success',
  failed:     'error',
  cancelled:  'neutral',
  processing: 'info',
  reversed:   'neutral',
  refunded:   'info',

  // KYC
  approved:      'success',
  rejected:      'error',
  under_review:  'warning',
  requires_info: 'warning',
  expired:       'neutral',

  // Agent / float
  enabled:   'success',
  disabled:  'neutral',
  on_hold:   'warning',

  // Risk / fraud
  flagged:   'warning',
  blocked_risk: 'error',
  cleared:   'success',

  // General
  open:      'warning',
  closed:    'neutral',
  resolved:  'success',
};

interface StatusBadgeProps {
  status: string;
  label?: string;
  className?: string;
}

export function StatusBadge({ status, label, className }: StatusBadgeProps) {
  const normalized = status.toLowerCase().replace(/[\s-]+/g, '_');
  const variant: BadgeVariant = STATUS_VARIANT_MAP[normalized] ?? 'neutral';
  const displayLabel = label ?? status.replace(/_/g, ' ');

  return (
    <Badge
      label={displayLabel}
      variant={variant}
      className={className ?? ''}
    />
  );
}
