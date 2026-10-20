'use client';

import Link from 'next/link';
import {
  Users,
  ClipboardList,
  UserCheck,
  ArrowLeftRight,
  RefreshCw,
  Percent,
  ArrowRight,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

interface QuickAction {
  label: string;
  href: string;
  icon: LucideIcon;
}

const QUICK_ACTIONS: QuickAction[] = [
  { label: 'Manage customers',    href: '/admin-portal/users',        icon: Users },
  { label: 'Review KYC requests', href: '/kyc',                       icon: ClipboardList },
  { label: 'Manage agents',       href: '/agents',                    icon: UserCheck },
  { label: 'View transactions',   href: '/admin-portal/transactions', icon: ArrowLeftRight },
  { label: 'Reconciliation',      href: '/reconciliation/batches',    icon: RefreshCw },
  { label: 'Fee rules',           href: '/fees/rules',                icon: Percent },
];

export function QuickActionsPanel() {
  return (
    <div className="bg-ok-card-bg border border-ok-card-border rounded-[11px] p-4 flex flex-col">
      <div className="mb-3">
        <h2 className="text-sm font-semibold text-text-primary">Quick actions</h2>
        <p className="text-xs text-text-secondary mt-0.5">Frequently used operations</p>
      </div>

      <ul className="flex flex-col gap-1.5">
        {QUICK_ACTIONS.map(({ label, href, icon: Icon }) => (
          <li key={href}>
            <Link
              href={href}
              className="flex items-center justify-between border border-ok-border rounded-md px-3 py-2.5 text-sm text-text-secondary hover:bg-ok-hover-bg hover:text-text-primary transition-colors group"
            >
              <span className="flex items-center gap-2.5">
                <Icon size={14} aria-hidden="true" className="shrink-0" />
                {label}
              </span>
              <ArrowRight
                size={13}
                aria-hidden="true"
                className="shrink-0 opacity-40 group-hover:opacity-100 transition-opacity"
              />
            </Link>
          </li>
        ))}
      </ul>
    </div>
  );
}
