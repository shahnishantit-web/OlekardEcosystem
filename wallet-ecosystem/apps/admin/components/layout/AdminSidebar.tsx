'use client';

import Link from 'next/link';
import { useAuth } from '@/lib/auth/context';
import { NAV_SECTIONS } from '@/src/lib/navigation/nav-items';

function getInitials(name?: string | null, email?: string | null): string {
  if (name) {
    const parts = name.trim().split(/\s+/);
    return parts.length >= 2
      ? ((parts[0]?.[0] ?? '') + (parts[parts.length - 1]?.[0] ?? '')).toUpperCase()
      : (parts[0]?.slice(0, 2) ?? '').toUpperCase();
  }
  return email ? email[0]!.toUpperCase() : 'A';
}

interface AdminSidebarProps {
  currentPath: string;
  collapsed: boolean;
  onToggle: () => void;
}

export function AdminSidebar({ currentPath, collapsed, onToggle: _onToggle }: AdminSidebarProps) {
  const { isAuthenticated, user, hasPermission: checkPermission } = useAuth();

  const hasPermission = (permission?: string) =>
    isAuthenticated && (!permission || checkPermission(permission));

  const isActive = (href: string) =>
    currentPath === href || currentPath.startsWith(href + '/');

  return (
    <aside
      id="admin-sidebar"
      className={[
        'flex flex-col h-full bg-ok-sidebar-bg border-r border-ok-sidebar-border sidebar-transition overflow-hidden',
        collapsed ? 'w-sidebar-collapsed' : 'w-sidebar-expanded',
      ].join(' ')}
      aria-label="Main navigation"
    >
      {/* Brand bar */}
      <div
        className="flex items-center border-b border-ok-sidebar-border shrink-0"
        style={{ height: 'var(--header-height)', padding: collapsed ? '0' : '0 16px' }}
      >
        {collapsed ? (
          <span className="text-ok-primary font-bold text-lg w-full text-center">OK</span>
        ) : (
          <>
            <div className="w-8 h-8 rounded-lg bg-[#344976] text-white grid place-items-center font-bold text-base mr-2.5 shrink-0">
              O
            </div>
            <span className="font-bold text-sm text-text-primary">
              OleKard <span className="text-text-secondary font-normal">/ Admin</span>
            </span>
          </>
        )}
      </div>

      {/* Nav sections */}
      <nav
        className="flex-1 overflow-y-auto py-2.5 scrollbar-thin"
        aria-label="Admin navigation"
      >
        {NAV_SECTIONS.map((section) => {
          if (!hasPermission(section.requiredPermission)) return null;
          const visibleItems = section.items.filter((item) =>
            hasPermission(item.requiredPermission)
          );
          if (visibleItems.length === 0) return null;

          return (
            <div key={section.title} className="mb-4">
              {!collapsed && (
                <div className="px-4 mb-1 text-[10px] font-semibold uppercase tracking-[0.11em] text-ok-text-muted select-none">
                  {section.title}
                </div>
              )}

              {visibleItems.map((item) => {
                const active = isActive(item.href);
                const Icon = item.icon;

                return (
                  <Link
                    key={item.href}
                    href={item.href}
                    aria-current={active ? 'page' : undefined}
                    aria-label={collapsed ? item.label : undefined}
                    title={collapsed ? item.label : undefined}
                    className={[
                      'relative flex items-center gap-3 mx-2.5 rounded-lg text-sm transition-colors',
                      'h-[38px]',
                      active
                        ? 'bg-ok-nav-active text-ok-nav-active-fg font-semibold'
                        : 'text-ok-nav-text hover:bg-ok-nav-hover hover:text-text-primary font-[530]',
                      collapsed ? 'justify-center px-0' : 'px-3',
                    ].join(' ')}
                  >
                    {active && (
                      <span
                        className="absolute left-0 top-1/2 -translate-y-1/2 w-0.5 h-4 rounded-r bg-ok-primary -ml-2.5"
                        aria-hidden="true"
                      />
                    )}
                    {Icon && (
                      <Icon
                        className="shrink-0"
                        size={16}
                        aria-hidden="true"
                      />
                    )}
                    {!collapsed && (
                      <span className="truncate">{item.label}</span>
                    )}
                  </Link>
                );
              })}
            </div>
          );
        })}
      </nav>

      {/* Bottom user account area */}
      <div
        className={[
          'border-t border-ok-sidebar-border shrink-0 flex items-center gap-2.5',
          collapsed ? 'justify-center p-3' : 'px-3 py-3',
        ].join(' ')}
      >
        <div
          className="w-[35px] h-[35px] rounded-full bg-[#dce4f5] text-[#475f93] flex items-center justify-center text-xs font-bold shrink-0"
          aria-hidden="true"
        >
          {getInitials(user?.name, user?.email)}
        </div>
        {!collapsed && (
          <div className="min-w-0">
            <p className="text-xs font-semibold text-text-primary truncate leading-tight">
              {user?.name ?? user?.email ?? 'Administrator'}
            </p>
            <p className="text-[11px] text-text-secondary leading-tight mt-0.5">Workspace Admin</p>
          </div>
        )}
      </div>
    </aside>
  );
}
