'use client';
import { usePathname } from 'next/navigation';
import type { ReactNode } from 'react';
import { SystemStatusBoundary } from './SystemStatusBoundary';
export function PublicStatusBoundary({ children }: {
    children: ReactNode;
}) {
    const pathname = usePathname();
    const publicPage = /^\/(login|forgot-password|reset-password|verify-email|account-locked|account-disabled)(\/|$)/.test(pathname);
    return publicPage ? <SystemStatusBoundary standalone>{children}</SystemStatusBoundary> : <>{children}</>;
}
