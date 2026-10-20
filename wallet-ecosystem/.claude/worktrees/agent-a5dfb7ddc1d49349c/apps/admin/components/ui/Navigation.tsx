import React from 'react';
import Link from 'next/link';

interface NavItem {
  href: string;
  label: string;
  isCurrent?: boolean;
}

interface NavigationProps {
  items: NavItem[];
  label?: string;
}

export function Navigation({ items, label = 'Main navigation' }: NavigationProps) {
  return (
    <nav aria-label={label}>
      <ul role="list">
        {items.map((item) => (
          <li key={item.href}>
            <Link
              href={item.href}
              aria-current={item.isCurrent ? 'page' : undefined}
            >
              {item.label}
            </Link>
          </li>
        ))}
      </ul>
    </nav>
  );
}
