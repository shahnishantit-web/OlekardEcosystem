import Link from 'next/link';

interface NavItem {
  href: string;
  label: string;
  icon?: React.ReactNode;
}

interface NavigationProps {
  items: NavItem[];
  currentPath: string;
  ariaLabel?: string;
}

export function Navigation({ items, currentPath, ariaLabel = 'Main navigation' }: NavigationProps) {
  return (
    <nav aria-label={ariaLabel}>
      <ul className="flex flex-col gap-1">
        {items.map((item) => {
          const isActive = currentPath === item.href;
          return (
            <li key={item.href}>
              <Link
                href={item.href}
                aria-current={isActive ? 'page' : undefined}
                className={`flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors focus-visible:outline-2 ${
                  isActive
                    ? 'bg-ok-nav-active text-brand-primary'
                    : 'text-text-secondary hover:text-text-primary hover:bg-neutral-100'
                }`}
                style={{ minHeight: '44px' }}
              >
                {item.icon && <span aria-hidden="true">{item.icon}</span>}
                {item.label}
              </Link>
            </li>
          );
        })}
      </ul>
    </nav>
  );
}
