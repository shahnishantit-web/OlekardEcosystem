import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { useAuth } from '@/lib/auth/context';
import { SecurityDashboardPage } from '@/src/modules/security/pages/dashboard';
import { AlertDetailPage } from '@/src/modules/security/pages/alert-detail';
import { SecurityFindingsPage } from '@/src/modules/security/pages/findings';
jest.mock('@/lib/auth/context', () => ({ useAuth: jest.fn() }));
const get = jest.fn(), post = jest.fn(), patch = jest.fn();
const client = { get, post, patch };
const detail = { id: 'alert-1', title: 'Suspicious transfer', description: 'Velocity exceeded', severity: 'high', alert_type: 'threshold_breach', subject_type: 'customer', subject_id: 'customer-1', created_at: '2026-10-14T10:00:00Z', status: 'open', version: 1, assigned_to: null, trigger: { description: 'Velocity exceeded', events: [] }, affected_transactions: [{ reference: 'TX-123', detected_at: '2026-10-14T10:00:00Z' }], entity_history: [{ id: 'ev-1', event_type: 'threshold_breach', severity: 'high', detected_at: '2026-10-14T10:00:00Z' }], risk_timeline: [{ id: 'eval-1', score: 85, risk_level: 'high', evaluated_at: '2026-10-14T10:00:00Z' }], notes: [], related_alerts: [], freeze_requests: [], active_hold: null, decisions: [] };
let permissions: string[];
beforeEach(() => {
    jest.clearAllMocks();
    permissions = ['security.alerts.read', 'risk.manage_alerts', 'risk.freeze_account', 'security.findings.read', 'security.findings.write'];
    (useAuth as jest.Mock).mockReturnValue({ authenticatedClient: client, user: { id: 'maker' }, hasPermission: (p: string) => permissions.includes(p) });
    get.mockResolvedValue({ data: [detail], meta: { last_page: 2 } });
    post.mockResolvedValue({ data: {} });
    patch.mockResolvedValue({ data: {} });
});
test('queue renders required columns, filters and real page query', async () => {
    render(<SecurityDashboardPage />);
    await screen.findByText('Suspicious transfer');
    for (const text of ['Severity', 'Type', 'Entity', 'Created', 'Assigned To'])
        expect(screen.getByRole('columnheader', { name: text })).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Alert severity'), { target: { value: 'critical' } });
    await waitFor(() => expect(get).toHaveBeenLastCalledWith(expect.stringContaining('severity=critical')));
    fireEvent.click(await screen.findByRole('button', { name: 'Next' }));
    await waitFor(() => expect(get).toHaveBeenLastCalledWith(expect.stringContaining('page=2')));
});
test('denied queue makes no API call', () => { permissions = []; render(<SecurityDashboardPage />); expect(screen.getByText('Access denied')).toBeInTheDocument(); expect(get).not.toHaveBeenCalled(); });
test('detail renders evidence and persists note/related link/assignment', async () => {
    get.mockResolvedValue({ data: detail });
    render(<AlertDetailPage id="alert-1"/>);
    await screen.findByText('TX-123');
    expect(screen.getByText('85')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Assign to me' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith('/v1/admin/security/alerts/alert-1/assign', { version: 1 }));
    fireEvent.change(screen.getByLabelText('Investigation note'), { target: { value: 'Checked source device' } });
    fireEvent.click(screen.getByRole('button', { name: 'Add note' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(expect.stringContaining('/notes'), { version: 1, note: 'Checked source device' }));
    fireEvent.change(screen.getByLabelText('Related alert ID'), { target: { value: 'alert-2' } });
    fireEvent.click(screen.getByRole('button', { name: 'Link alert' }));
    await waitFor(() => expect(post).toHaveBeenCalledWith(expect.stringContaining('/links'), { version: 1, related_alert_id: 'alert-2' }));
});
test('freeze requires reason and displays error without closing confirmation', async () => {
    get.mockResolvedValue({ data: detail });
    post.mockRejectedValue({ error: { message: 'Freeze conflict' } });
    render(<AlertDetailPage id="alert-1"/>);
    fireEvent.click(await screen.findByRole('button', { name: 'Freeze Account' }));
    const confirm = screen.getByRole('button', { name: 'Request freeze' });
    expect(confirm).toBeDisabled();
    fireEvent.change(screen.getByLabelText('Freeze reason'), { target: { value: 'Confirmed suspicious transfer' } });
    fireEvent.click(confirm);
    expect(await screen.findByText('Freeze conflict')).toBeInTheDocument();
    expect(screen.getByRole('dialog')).toBeInTheDocument();
});
test('only independent checker can approve pending freeze', async () => {
    get.mockResolvedValue({ data: { ...detail, freeze_requests: [{ id: 'req', maker_id: 'maker', reason: 'Suspicious activity', status: 'pending' }] } });
    render(<AlertDetailPage id="alert-1"/>);
    await screen.findByText('Pending freeze approval');
    expect(screen.queryByRole('button', { name: 'Approve freeze' })).not.toBeInTheDocument();
});
test('findings shows CVE and sends additive lifecycle status', async () => {
    get.mockResolvedValue({ data: [{ id: 'f1', finding_ref: 'CVE-2026-1234', cve_id: 'CVE-2026-1234', severity: 'high', component: 'library', title: 'Vulnerable dependency', status: 'open', detected_at: '2026-10-14T10:00:00Z' }], meta: { last_page: 1 } });
    render(<SecurityFindingsPage />);
    await screen.findByText('CVE-2026-1234');
    fireEvent.click(screen.getByRole('button', { name: 'Acknowledge' }));
    await waitFor(() => expect(patch).toHaveBeenCalledWith('/v1/security/findings/f1/status', { status: 'acknowledged' }));
});
test('read-only findings has no mutation controls', async () => {
    permissions = ['security.findings.read'];
    get.mockResolvedValue({ data: [{ id: 'f1', severity: 'high', component: 'library', title: 'Finding', status: 'acknowledged', detected_at: '2026-10-14T10:00:00Z' }], meta: { last_page: 1 } });
    render(<SecurityFindingsPage />);
    await screen.findByText('library');
    expect(screen.queryByRole('button', { name: 'Start remediation' })).not.toBeInTheDocument();
});
