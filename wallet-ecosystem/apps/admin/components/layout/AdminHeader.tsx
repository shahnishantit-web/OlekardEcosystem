'use client';

import { useState, useRef, useEffect } from 'react';
import { Bell, Search, ChevronDown, LogOut } from 'lucide-react';
import { Breadcrumbs } from './Breadcrumbs';
import { useBreadcrumbs } from '@/lib/hooks/useBreadcrumbs';
import { useAuth } from '@/lib/auth/context';

interface AdminHeaderProps {
  onSidebarToggle: () => void;
  sidebarCollapsed: boolean;
}

export function AdminHeader({ onSidebarToggle, sidebarCollapsed }: AdminHeaderProps) {
  const breadcrumbs = useBreadcrumbs();
  const { user, signOut } = useAuth();
  const [profileOpen, setProfileOpen] = useState(false);
  const profileRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleOutsideClick = (e: MouseEvent) => {
      if (profileRef.current && !profileRef.current.contains(e.target as Node)) {
        setProfileOpen(false);
      }
    };
    document.addEventListener('mousedown', handleOutsideClick);
    return () => document.removeEventListener('mousedown', handleOutsideClick);
  }, []);

  const avatarInitials = user?.name
    ? user.name.trim().split(/\s+/).map((p) => p[0] ?? '').slice(0, 2).join('').toUpperCase()
    : user?.email
    ? (user.email[0] ?? 'A').toUpperCase()
    : 'A';

  return (
    <header
      className="flex items-center px-[34px] gap-3 bg-ok-header-bg border-b border-ok-header-border sticky top-0 z-30"
      style={{ height: 'var(--header-height)' }}
    >
      {/* Sidebar toggle */}
      <button
        type="button"
        onClick={onSidebarToggle}
        aria-label={sidebarCollapsed ? 'Expand navigation' : 'Collapse navigation'}
        aria-expanded={!sidebarCollapsed}
        aria-controls="admin-sidebar"
        className="p-2 rounded-lg text-text-secondary hover:text-text-primary hover:bg-ok-hover-bg transition-colors focus-visible:outline-2 shrink-0"
      >
        <svg width="18" height="18" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
          <rect x="2" y="5" width="16" height="2" rx="1" />
          <rect x="2" y="9" width="16" height="2" rx="1" />
          <rect x="2" y="13" width="16" height="2" rx="1" />
        </svg>
      </button>

      {/* Breadcrumbs */}
      <div className="flex-1 min-w-0 hidden sm:block">
        <Breadcrumbs items={breadcrumbs} />
      </div>

      {/* Right controls */}
      <div className="flex items-center gap-1 ml-auto shrink-0">
        {/* Search */}
        <form
          action="/admin-portal/users"
          method="get"
          role="search"
          className="hidden md:flex items-center"
        >
          <div className="relative">
            <Search
              size={13}
              className="absolute left-2.5 top-1/2 -translate-y-1/2 text-text-secondary/50 pointer-events-none"
              aria-hidden="true"
            />
            <input
              type="search"
              name="search"
              placeholder="Search workspace…"
              aria-label="Search users"
              className="w-48 pl-8 pr-3 h-[33px] text-sm bg-ok-hover-bg border border-ok-border rounded-lg text-text-primary placeholder:text-text-secondary/70 focus:outline-none focus:border-ok-primary transition-colors"
            />
            <span className="absolute right-2 top-1/2 -translate-y-1/2 text-[10px] text-text-secondary/50 border border-ok-border rounded px-1 hidden lg:block">
              ⌘K
            </span>
          </div>
        </form>

        {/* Notifications */}
        <a
          href="/audit"
          aria-label="View audit events"
          title="Audit events"
          className="relative p-2 rounded-lg text-text-secondary hover:text-text-primary hover:bg-ok-hover-bg transition-colors"
        >
          <Bell size={17} aria-hidden="true" />
          <span className="absolute top-1.5 right-1.5 w-1.5 h-1.5 rounded-full bg-[#e8a044]" aria-hidden="true" />
        </a>

        {/* Profile menu */}
        <div ref={profileRef} className="relative">
          <button
            type="button"
            onClick={() => setProfileOpen((o) => !o)}
            aria-label="Open profile menu"
            aria-expanded={profileOpen}
            aria-haspopup="menu"
            className="flex items-center gap-1.5 pl-1.5 pr-2 py-1.5 rounded-lg text-text-secondary hover:text-text-primary hover:bg-ok-hover-bg transition-colors text-sm"
          >
            <span
              className="w-[29px] h-[29px] rounded-full bg-[#dce4f5] text-[#475f93] flex items-center justify-center text-[10px] font-bold shrink-0 select-none"
              aria-hidden="true"
            >
              {avatarInitials}
            </span>
            <span className="hidden lg:block max-w-[140px] truncate text-sm">
              {user?.email ?? 'Admin'}
            </span>
            <ChevronDown
              size={13}
              className={`transition-transform ${profileOpen ? 'rotate-180' : ''}`}
              aria-hidden="true"
            />
          </button>

          {profileOpen && (
            <div
              role="menu"
              className="absolute right-0 top-full mt-1 w-56 bg-ok-surface border border-ok-card-border rounded-xl shadow-[0_4px_16px_rgba(32,38,54,0.10)] py-1 z-50"
            >
              <div className="px-3 py-2.5 border-b border-ok-border">
                {user?.name && (
                  <div className="text-sm font-semibold text-text-primary truncate">
                    {user.name}
                  </div>
                )}
                <div className="text-xs text-text-secondary truncate mt-0.5">
                  {user?.email ?? '—'}
                </div>
              </div>
              <button
                type="button"
                role="menuitem"
                onClick={() => {
                  setProfileOpen(false);
                  signOut();
                }}
                className="w-full flex items-center gap-2.5 px-3 py-2 text-sm text-text-secondary hover:text-text-primary hover:bg-ok-hover-bg transition-colors"
              >
                <LogOut size={14} aria-hidden="true" />
                Sign out
              </button>
            </div>
          )}
        </div>
      </div>
    </header>
  );
}
