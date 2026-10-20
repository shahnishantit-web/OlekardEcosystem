import Link from 'next/link';
import { ClipboardList, AlertTriangle, ArrowRight } from 'lucide-react';

interface PendingTasksCardProps {
  kycPending: number | null;
  openDisputes: number | null;
  loading?: boolean;
}

interface TaskItem {
  label: string;
  count: number | null;
  href: string | null;
  icon: React.ElementType;
  urgency: 'high' | 'normal';
}

function TaskRow({ item }: { item: TaskItem }) {
  const Icon = item.icon;
  const countDisplay =
    item.count === null ? '—' : item.count.toLocaleString();

  const inner = (
    <div className="flex items-center gap-3 py-2">
      <span
        className={[
          'flex items-center justify-center w-7 h-7 rounded-md shrink-0',
          item.urgency === 'high' && item.count
            ? 'bg-ok-warning/15 text-ok-warning'
            : 'bg-neutral-200/50 text-text-secondary',
        ].join(' ')}
      >
        <Icon size={14} aria-hidden="true" />
      </span>
      <span className="flex-1 text-sm text-text-primary">{item.label}</span>
      <span
        className={[
          'text-sm font-semibold tabular-nums',
          item.urgency === 'high' && item.count
            ? 'text-ok-warning'
            : 'text-text-primary',
        ].join(' ')}
      >
        {countDisplay}
      </span>
      {item.href && (
        <ArrowRight size={12} className="text-text-secondary shrink-0" aria-hidden="true" />
      )}
    </div>
  );

  if (item.href) {
    return (
      <li>
        <Link
          href={item.href}
          className="block hover:bg-ok-hover-bg rounded-md transition-colors -mx-1 px-1"
          aria-label={`${item.label}: ${countDisplay}`}
        >
          {inner}
        </Link>
      </li>
    );
  }

  return <li aria-label={`${item.label}: ${countDisplay}`}>{inner}</li>;
}

export function PendingTasksCard({ kycPending, openDisputes, loading }: PendingTasksCardProps) {
  const items: TaskItem[] = [
    {
      label: 'KYC Awaiting Review',
      count: kycPending,
      href: '/kyc',
      icon: ClipboardList,
      urgency: 'high',
    },
    {
      label: 'Unresolved Disputes',
      count: openDisputes,
      href: null,
      icon: AlertTriangle,
      urgency: 'high',
    },
  ];

  return (
    <section
      className="bg-ok-surface border border-ok-border rounded-lg p-4"
      aria-label="Pending actions"
    >
      <h2 className="text-sm font-semibold text-text-primary mb-3">Pending Actions</h2>

      {loading ? (
        <ul aria-busy="true" className="space-y-2">
          {items.map((item) => (
            <li key={item.label} className="flex items-center gap-3 py-2">
              <div className="w-7 h-7 rounded-md bg-neutral-200 animate-pulse" />
              <div className="h-3 flex-1 rounded bg-neutral-200 animate-pulse" />
              <div className="h-3 w-8 rounded bg-neutral-200 animate-pulse" />
            </li>
          ))}
        </ul>
      ) : (
        <ul className="divide-y divide-ok-border">
          {items.map((item) => (
            <TaskRow key={item.label} item={item} />
          ))}
        </ul>
      )}
    </section>
  );
}
