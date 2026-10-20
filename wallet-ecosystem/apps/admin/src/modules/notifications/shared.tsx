'use client';
import Link from 'next/link';
import { useAuth } from '@/lib/auth/context';
export const field = 'block w-full rounded-lg border border-ok-border-strong bg-ok-surface p-2 text-text-primary focus-visible:outline-2';
export function NotificationNav() { const { hasPermission } = useAuth(); return <nav aria-label="Notification management" className="flex flex-wrap gap-4 mb-6 text-sm">{[['templates', 'Templates'], ['log', 'Delivery log'], ['rules', 'Rules']].map(([path, label]) => hasPermission('notifications.' + path + '.read') && <Link key={path} className="underline" href={'/notifications/' + path}>{label}</Link>)}</nav>; }
