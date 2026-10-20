'use client';
import { useEffect, useState, type ReactNode } from 'react';
import { usePathname } from 'next/navigation';
import { SYSTEM_STATUS_EVENT, type SystemFailure } from '@/lib/system/http-status';
import { SystemStatusView } from './SystemStatusView';
export function SystemStatusBoundary({ children, standalone = false }: {
    children: ReactNode;
    standalone?: boolean;
}) {
    const pathname = usePathname();
    const [failure, setFailure] = useState<SystemFailure | null>(null), [offline, setOffline] = useState(false);
    useEffect(() => { setFailure(null); }, [pathname]);
    useEffect(() => { const update = () => setOffline(!navigator.onLine); const receive = (event: Event) => { const d = (event as CustomEvent<SystemFailure>).detail; if (d !== undefined && (d.status === 429 || d.status === 503 || d.status === 504))
        setFailure(d); }; update(); window.addEventListener('offline', update); window.addEventListener('online', update); window.addEventListener(SYSTEM_STATUS_EVENT, receive); return () => { window.removeEventListener('offline', update); window.removeEventListener('online', update); window.removeEventListener(SYSTEM_STATUS_EVENT, receive); }; }, []);
    if (offline)
        return <SystemStatusView code="offline" embedded={!standalone}/>;
    if (failure !== null)
        return <SystemStatusView code={String(failure.status) as '429' | '503' | '504'} detail={failure} embedded={!standalone}/>;
    return <>{children}</>;
}
