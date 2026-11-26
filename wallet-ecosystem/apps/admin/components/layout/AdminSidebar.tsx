'use client';

import Link from 'next/link';
import Image from 'next/image';
import { useEffect, useState } from 'react';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useAuth } from '@/lib/auth/context';
import { isNavGroup, NAV_SECTIONS, type NavEntry } from '@/src/lib/navigation/nav-items';

function getInitials(name?: string | null, email?: string | null): string {
  if (name !== undefined && name !== null && name !== '') {
    const parts = name.trim().split(/\s+/);
    return parts.length >= 2
      ? ((parts[0]?.[0] ?? '') + (parts[parts.length - 1]?.[0] ?? '')).toUpperCase()
      : (parts[0]?.slice(0, 2) ?? '').toUpperCase();
  }
  return email !== undefined && email !== null && email !== '' ? email[0]!.toUpperCase() : 'A';
}

function pathIsActive(currentPath: string, href: string): boolean {
  return currentPath === href || currentPath.startsWith(href + '/');
}

interface AdminSidebarProps {
  currentPath: string;
  collapsed: boolean;
  onToggle: () => void;
}

export function AdminSidebar({ currentPath, collapsed, onToggle: _onToggle }: AdminSidebarProps) {
  const { isAuthenticated, user, hasPermission: checkPermission } = useAuth();
  const [openGroups, setOpenGroups] = useState<Record<string, boolean>>({});

  const hasPermission = (item: { requiredPermission?: string; requiredPermissions?: string[] }) => {
    if (!isAuthenticated) return false;
    if (item.requiredPermission !== undefined && item.requiredPermission !== '') {
      return checkPermission(item.requiredPermission);
    }
    if (item.requiredPermissions !== undefined && item.requiredPermissions.length > 0) {
      return item.requiredPermissions.some((permission) => checkPermission(permission));
    }
    return false;
  };

  useEffect(() => {
    setOpenGroups((current) => {
      const next = { ...current };
      NAV_SECTIONS.forEach((section) => section.items.forEach((entry) => {
        if (isNavGroup(entry)
          && entry.items.some((item) => pathIsActive(currentPath, item.href))) {
          next[section.title + ':' + entry.label] = true;
        }
      }));
      return next;
    });
  }, [currentPath]);

  const toggleGroup = (key: string) => {
    if (collapsed) {
      setOpenGroups((current) => ({ ...current, [key]: true }));
      _onToggle();
      return;
    }
    setOpenGroups((current) => ({ ...current, [key]: current[key] !== true }));
  };

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
          <Image
            src="/icon.png"
            alt="OleKard"
            width={30}
            height={30}
            className="mx-auto rounded-md"
          />
        ) : (
          <>
            <Image
              src="/olekard-logo.png"
              alt="OleKard"
              width={526}
              height={114}
              className="mr-2.5 h-auto w-[118px] shrink-0"
            />
            <span className="font-bold text-sm text-text-primary">
              Admin
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
          if (section.requiredPermission !== undefined && section.requiredPermission !== ''
            && !checkPermission(section.requiredPermission)) return null;
          const visibleItems = section.items.reduce<NavEntry[]>((visible, entry) => {
            if (isNavGroup(entry)) {
              if (entry.requiredPermission !== undefined && entry.requiredPermission !== ''
                && !hasPermission(entry)) return visible;
              const items = entry.items.filter((item) => hasPermission(item));
              if (items.length > 0) visible.push({ ...entry, items });
              return visible;
            }
            if (hasPermission(entry)) visible.push(entry);
            return visible;
          }, []);
          if (visibleItems.length === 0) return null;

          return (
            <div key={section.title} className="mb-4">
              {!collapsed && (
                <div className="px-4 mb-1 text-[10px] font-semibold uppercase tracking-[0.11em] text-ok-text-muted select-none">
                  {section.title}
                </div>
              )}

              {visibleItems.map((entry) => {
                if (isNavGroup(entry)) {
                  const groupKey = section.title + ':' + entry.label;
                  const active = entry.items.some((item) => pathIsActive(currentPath, item.href));
                  const open = !collapsed && Boolean(openGroups[groupKey]);
                  const Icon = entry.icon;
                  return (
                    <div key={groupKey}>
                      <button
                        type="button"
                        aria-expanded={open}
                        aria-controls={'nav-group-' + groupKey.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}
                        aria-label={collapsed ? entry.label : undefined}
                        title={collapsed ? entry.label : undefined}
                        onClick={() => toggleGroup(groupKey)}
                        className={[
                          'relative flex items-center gap-3 mx-2.5 rounded-lg text-sm transition-colors w-[calc(100%-20px)]',
                          'h-[38px]',
                          active ? 'text-ok-nav-active-fg font-semibold' : 'text-ok-nav-text hover:bg-ok-nav-hover hover:text-text-primary font-[530]',
                          collapsed ? 'justify-center px-0' : 'px-3',
                        ].join(' ')}
                      >
                        {active && <span className="absolute left-0 top-1/2 -translate-y-1/2 w-0.5 h-4 rounded-r bg-ok-primary -ml-2.5" aria-hidden="true"/>}
                        {Icon && <Icon className="shrink-0" size={16} aria-hidden="true"/>}
                        {!collapsed && <><span className="truncate flex-1 text-left">{entry.label}</span>{open ? <ChevronDown size={14} aria-hidden="true"/> : <ChevronRight size={14} aria-hidden="true"/>}</>}
                      </button>
                      {open && <div id={'nav-group-' + groupKey.replace(/[^a-z0-9]+/gi, '-').toLowerCase()} className="ml-6 pl-2 border-l border-ok-sidebar-border mt-1 mb-2">
                        {entry.items.map((item) => {
                          const itemActive = pathIsActive(currentPath, item.href);
                          const ItemIcon = item.icon;
                          return <Link key={item.href} href={item.href} aria-current={itemActive ? 'page' : undefined} className={[
                            'flex items-center gap-2.5 mx-1 rounded-md text-[13px] transition-colors h-[34px] px-2.5',
                            itemActive ? 'bg-ok-nav-active text-ok-nav-active-fg font-semibold' : 'text-ok-nav-text hover:bg-ok-nav-hover hover:text-text-primary',
                          ].join(' ')}>{ItemIcon && <ItemIcon className="shrink-0" size={14} aria-hidden="true"/>}<span className="truncate">{item.label}</span></Link>;
                        })}
                      </div>}
                    </div>
                  );
                }

                const active = pathIsActive(currentPath, entry.href);
                const Icon = entry.icon;
                return (
                  <Link
                    key={entry.href}
                    href={entry.href}
                    aria-current={active ? 'page' : undefined}
                    aria-label={collapsed ? entry.label : undefined}
                    title={collapsed ? entry.label : undefined}
                    className={[
                      'relative flex items-center gap-3 mx-2.5 rounded-lg text-sm transition-colors',
                      'h-[38px]',
                      active
                        ? 'bg-ok-nav-active text-ok-nav-active-fg font-semibold'
                        : 'text-ok-nav-text hover:bg-ok-nav-hover hover:text-text-primary font-[530]',
                      collapsed ? 'justify-center px-0' : 'px-3',
                    ].join(' ')}
                  >
                    {active && <span className="absolute left-0 top-1/2 -translate-y-1/2 w-0.5 h-4 rounded-r bg-ok-primary -ml-2.5" aria-hidden="true"/>}
                    {Icon && <Icon className="shrink-0" size={16} aria-hidden="true"/>}
                    {!collapsed && <span className="truncate">{entry.label}</span>}
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
