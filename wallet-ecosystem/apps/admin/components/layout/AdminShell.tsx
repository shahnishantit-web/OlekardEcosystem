'use client';
import { useState, useEffect, useCallback, useRef } from 'react';
import { usePathname, useRouter } from 'next/navigation';
import { AdminSidebar } from './AdminSidebar';
import { AdminHeader } from './AdminHeader';
import { SystemStatusBoundary } from '@/components/system/SystemStatusBoundary';
import { useAuth } from '@/lib/auth/context';
const STORAGE_KEY = 'ok-sidebar-collapsed';
interface AdminShellProps {
    children: React.ReactNode;
}
export function AdminShell({ children }: AdminShellProps) {
    const pathname = usePathname();
    const router = useRouter();
    const { phase } = useAuth();
    const [collapsed, setCollapsed] = useState(false);
    const [drawerOpen, setDrawerOpen] = useState(false);
    // Track whether the session was ever authenticated in this shell instance.
    // If the phase later becomes unauthenticated (e.g. a 401 from the API clears
    // the session), redirect to login with a session-expired notice.
    const wasAuthenticated = useRef(false);
    useEffect(() => {
        if (phase === 'authenticated') {
            wasAuthenticated.current = true;
        }
        else if (phase === 'unauthenticated' && wasAuthenticated.current) {
            router.replace('/login?reason=session_expired');
        }
    }, [phase, router]);
    // Read persisted collapse state on mount (tab-scoped via sessionStorage)
    useEffect(() => {
        const stored = sessionStorage.getItem(STORAGE_KEY);
        if (stored !== null) {
            setCollapsed(stored === 'true');
        }
    }, []);
    const handleToggle = useCallback(() => {
        setCollapsed((prev) => {
            const next = !prev;
            sessionStorage.setItem(STORAGE_KEY, String(next));
            return next;
        });
    }, []);
    const handleDrawerToggle = useCallback(() => {
        setDrawerOpen((prev) => !prev);
    }, []);
    const handleHeaderToggle = useCallback(() => {
        if (window.innerWidth >= 1024) {
            handleToggle();
        }
        else {
            handleDrawerToggle();
        }
    }, [handleToggle, handleDrawerToggle]);
    // Close drawer on route change
    useEffect(() => {
        setDrawerOpen(false);
    }, [pathname]);
    // Close mobile drawer on Escape key
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && drawerOpen)
                setDrawerOpen(false);
        };
        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [drawerOpen]);
    return (<div className="flex h-screen overflow-hidden bg-ok-background">
      {/* Desktop sidebar */}
      <div className="hidden lg:flex shrink-0">
        <AdminSidebar currentPath={pathname} collapsed={collapsed} onToggle={handleToggle}/>
      </div>

      {/* Mobile drawer overlay */}
      {drawerOpen && (<div className="fixed inset-0 z-40 lg:hidden" onClick={() => setDrawerOpen(false)} aria-hidden="true">
          <div className="absolute inset-0 bg-black/60"/>
        </div>)}

      {/* Mobile drawer */}
      {drawerOpen && (<div className="fixed inset-y-0 left-0 z-50 lg:hidden" role="dialog" aria-modal="true" aria-label="Navigation menu">
          <AdminSidebar currentPath={pathname} collapsed={false} onToggle={() => setDrawerOpen(false)}/>
        </div>)}

      {/* Main content area */}
      <div className="flex flex-col flex-1 min-w-0 overflow-hidden">
        <AdminHeader onSidebarToggle={handleHeaderToggle} sidebarCollapsed={collapsed}/>
        <main id="main-content" className="flex-1 overflow-y-auto" tabIndex={-1}>
          <SystemStatusBoundary>{children}</SystemStatusBoundary>
        </main>
      </div>
    </div>);
}
