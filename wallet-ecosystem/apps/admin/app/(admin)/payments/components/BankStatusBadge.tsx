'use client';

// T090: Status badge for bank and account lifecycle states.
import type { AccountLifecycleStatus, BankLifecycleStatus } from '@/lib/api/payments/banks';

type StatusColor = 'slate' | 'blue' | 'yellow' | 'orange' | 'green' | 'red' | 'gray';

const BANK_STATUS_COLORS: Record<BankLifecycleStatus, StatusColor> = {
  draft:     'slate',
  active:    'green',
  suspended: 'orange',
  retired:   'gray',
};

const ACCOUNT_STATUS_COLORS: Record<AccountLifecycleStatus, StatusColor> = {
  draft:           'slate',
  validated:       'blue',
  sandbox_testing: 'blue',
  evidence_review: 'yellow',
  approved:        'yellow',
  active:          'green',
  suspended:       'orange',
  retired:         'gray',
};

const COLOR_CLASSES: Record<StatusColor, string> = {
  slate:  'bg-slate-100 text-slate-700 border-slate-200',
  blue:   'bg-blue-100 text-blue-700 border-blue-200',
  yellow: 'bg-yellow-100 text-yellow-700 border-yellow-200',
  orange: 'bg-orange-100 text-orange-700 border-orange-200',
  green:  'bg-green-100 text-green-700 border-green-200',
  red:    'bg-red-100 text-red-700 border-red-200',
  gray:   'bg-gray-100 text-gray-500 border-gray-200',
};

interface Props {
  status: BankLifecycleStatus | AccountLifecycleStatus;
  type: 'bank' | 'account';
  className?: string;
}

export function BankStatusBadge({ status, type, className = '' }: Props) {
  const color =
    type === 'bank'
      ? (BANK_STATUS_COLORS[status as BankLifecycleStatus] ?? 'slate')
      : (ACCOUNT_STATUS_COLORS[status as AccountLifecycleStatus] ?? 'slate');

  const label = status.replace(/_/g, ' ');

  return (
    <span
      className={`inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium capitalize ${COLOR_CLASSES[color]} ${className}`}
    >
      {label}
    </span>
  );
}
