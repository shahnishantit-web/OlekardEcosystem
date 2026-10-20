'use client';

import { usePathname } from 'next/navigation';
import { NAV_SECTIONS } from '@/src/lib/navigation/nav-items';

interface BreadcrumbItem {
  label: string;
  href?: string;
}

export function useBreadcrumbs(): BreadcrumbItem[] {
  const pathname = usePathname();

  const crumbs: BreadcrumbItem[] = [{ label: 'Dashboard', href: '/dashboard' }];

  if (pathname === '/dashboard') return crumbs;

  for (const section of NAV_SECTIONS) {
    for (const item of section.items) {
      if (pathname === item.href || pathname.startsWith(item.href + '/')) {
        crumbs.push({ label: section.title });
        crumbs.push(
          pathname === item.href
            ? { label: item.label }
            : { label: item.label, href: item.href },
        );
        return crumbs;
      }
    }
  }

  // Fallback: derive label from last path segment
  const segments = pathname.split('/').filter(Boolean);
  const lastSegment = segments[segments.length - 1] ?? '';
  const label = lastSegment
    .replace(/-/g, ' ')
    .replace(/\b\w/g, (c) => c.toUpperCase());
  crumbs.push({ label });
  return crumbs;
}
