import React from 'react';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { axe } from 'jest-axe';
import { SystemStatusView } from '@/components/system/SystemStatusView';
import { SystemStatusBoundary } from '@/components/system/SystemStatusBoundary';
import { SYSTEM_STATUS_EVENT } from '@/lib/system/http-status';
jest.mock('next/navigation', () => ({ usePathname: () => '/security/events' }));
jest.mock('next/link', () => { const Link = ({ href, children, ...props }: {
    href: string;
    children: React.ReactNode;
}) => <a href={href} {...props}>{children}</a>; return Link; });
describe('p2001 accessible status presentation', () => {
    it.each(['401', '429', '503', '504', 'offline'] as const)('renders %s with code or state and recovery action', code => { render(<SystemStatusView code={code}/>); expect(screen.getByRole('heading', { level: 1 })).toBeVisible(); expect(screen.getAllByRole('link')[0]).toBeVisible(); });
    it('displays unknown maintenance return time without inventing an estimate', async () => { const { container } = render(<main><SystemStatusView code="maintenance"/></main>); expect(screen.getByText(/Not yet available/)).toBeVisible(); expect((await axe(container)).violations).toEqual([]); });
    it('replaces only shell content on status/offline and restores on connectivity', () => {
        render(<div><nav>Shell navigation</nav><SystemStatusBoundary><p>Business page</p></SystemStatusBoundary></div>);
        act(() => window.dispatchEvent(new CustomEvent(SYSTEM_STATUS_EVENT, { detail: { status: 503, correlationId: 'safe-ref' } })));
        expect(screen.getByText('Shell navigation')).toBeVisible();
        expect(screen.getByRole('heading', { name: 'Service temporarily unavailable' })).toBeVisible();
        expect(screen.queryByText('Business page')).not.toBeInTheDocument();
    });
    it('shows offline and restores current content when online', () => {
        render(<SystemStatusBoundary><p>Business page</p></SystemStatusBoundary>);
        Object.defineProperty(navigator, 'onLine', { value: false, configurable: true });
        fireEvent(window, new Event('offline'));
        expect(screen.getByRole('heading', { name: 'You are offline' })).toBeVisible();
        Object.defineProperty(navigator, 'onLine', { value: true, configurable: true });
        fireEvent(window, new Event('online'));
        expect(screen.getByText('Business page')).toBeVisible();
    });
});
