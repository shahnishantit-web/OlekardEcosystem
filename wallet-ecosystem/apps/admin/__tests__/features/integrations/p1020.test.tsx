import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { ApiClientListPage } from '@/src/modules/integrations/pages/api-client-list';
import { IntegrationListPage } from '@/src/modules/integrations/pages/integration-list';
import { WebhookMonitorPage } from '@/src/modules/integrations/pages/webhook-monitor';
import { ProviderHealthPage } from '@/src/modules/integrations/pages/provider-health';
const mockClient = { get: jest.fn(), post: jest.fn(), patch: jest.fn(), delete: jest.fn() };
let mockAllowed = true;
jest.mock('@/lib/auth/context', () => ({ useAuth: () => ({ authenticatedClient: mockClient, hasPermission: () => mockAllowed }) }));
const partner = { id: 'partner-1', display_name: 'Test partner', provider_type: 'sms', provider_slug: 'test', health: 'healthy', last_checked_at: '2026-10-15T06:00:00Z', status: 'active', is_default: true };
const client = { id: 'client-1', name: 'Partner app', api_key_prefix: '12345678', status: 'active', permissions: ['integrations.manage'], ip_whitelist: ['192.0.2.0/24'], integration_id: 'partner-1', rate_limit_per_minute: 60, last_used_at: null };
const meta = { current_page: 1, last_page: 1, total: 1 };
beforeEach(() => { jest.clearAllMocks(); mockAllowed = true; mockClient.get.mockImplementation((p: string) => Promise.resolve(p.includes('/api-clients') ? { data: [client], meta } : { data: [partner], meta })); });
test('unauthorized integration surface does not fetch', () => { mockAllowed = false; render(<IntegrationListPage />); expect(screen.getByRole('alert')).toHaveTextContent('Access denied'); expect(mockClient.get).not.toHaveBeenCalled(); });
test('new client captures partner permissions CIDR and one-time secret dismissal', async () => {
    mockClient.post.mockResolvedValue({ data: { ...client, api_key: 'test-key', raw_secret: 'test-only-secret' } });
    render(<ApiClientListPage partnerId="partner-1"/>);
    await screen.findByRole('option', { name: 'Test partner' });
    fireEvent.change(screen.getByLabelText('Client name'), { target: { value: 'Partner app' } });
    fireEvent.change(screen.getByLabelText('Permissions'), { target: { value: 'integrations.manage' } });
    fireEvent.change(screen.getByLabelText('IP whitelist'), { target: { value: '192.0.2.0/24' } });
    fireEvent.click(screen.getByRole('button', { name: 'Create client' }));
    expect(await screen.findByLabelText('Client secret')).toHaveValue('test-only-secret');
    expect(mockClient.post).toHaveBeenCalledWith('/v1/admin/integrations/api-clients', expect.objectContaining({ integration_id: 'partner-1', ip_whitelist: ['192.0.2.0/24'] }));
    fireEvent.click(screen.getByRole('button', { name: 'I have saved the credentials' }));
    expect(screen.queryByLabelText('Client secret')).not.toBeInTheDocument();
    expect(localStorage.length).toBe(0);
});
test('credential rotate and revoke require explicit confirmation', async () => {
    mockClient.post.mockResolvedValue({ data: { ...client, api_key: 'test-key', raw_secret: 'rotated-test-secret' } });
    mockClient.delete.mockResolvedValue({ data: {} });
    render(<ApiClientListPage />);
    fireEvent.click(await screen.findByRole('button', { name: 'Rotate' }));
    expect(mockClient.post).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Confirm rotate' }));
    await screen.findByLabelText('Client secret');
    fireEvent.click(await screen.findByRole('button', { name: 'Revoke' }));
    fireEvent.click(screen.getByRole('button', { name: 'Confirm revoke' }));
    await screen.findByText('Credentials revoked.');
    expect(mockClient.delete).toHaveBeenCalledWith('/v1/admin/integrations/api-clients/client-1');
});
test('webhook filters and failed retry map to existing callback id', async () => {
    mockClient.get.mockResolvedValue({ data: [{ id: 'event-1', event: 'notice', endpoint: '/v1/webhooks/test', status: 'failed', attempts: 2, last_attempt_at: null }], meta });
    mockClient.post.mockResolvedValue({ data: {} });
    render(<WebhookMonitorPage />);
    await screen.findByRole('button', { name: 'Retry' });
    fireEvent.change(screen.getByLabelText('Webhook status'), { target: { value: 'failed' } });
    fireEvent.change(screen.getByLabelText('Event type'), { target: { value: 'notice' } });
    await waitFor(() => expect(mockClient.get).toHaveBeenLastCalledWith(expect.stringContaining('event_type=notice')));
    fireEvent.click(await screen.findByRole('button', { name: 'Retry' }));
    await screen.findByText('Callback retry queued.');
    expect(mockClient.post).toHaveBeenCalledWith('/v1/admin/webhooks/deliveries/event-1/retry', {});
});
test('health exposes unknown buckets and safe error log', async () => {
    mockClient.get.mockResolvedValue({ data: { integration_id: 'partner-1', display_name: 'Test partner', health: 'degraded', last_checked_at: null, sample_count: 1, buckets: [{ hour: '2026-10-15T00:00:00Z', samples: 0, uptime_percent: null }], recent_errors: [{ timestamp: '2026-10-15T06:00:00Z', status: 'degraded', message: 'Provider probe timed out' }] } });
    render(<ProviderHealthPage providerId="partner-1"/>);
    expect(await screen.findByRole('img', { name: '24-hour observed uptime chart' })).toBeInTheDocument();
    expect(screen.getByText('Provider probe timed out')).toBeInTheDocument();
    expect(screen.getByText(/Missing observations are unknown/)).toBeInTheDocument();
});

test('development payment evidence is labelled and can be quarantined', async () => {
    mockClient.get.mockResolvedValue({ data: [{ id: 'receipt-1', event: 'payment.success', endpoint: '/v1/webhooks/test', status: 'retrying', attempts: 0, last_attempt_at: null, test_only: true }], meta });
    mockClient.post.mockResolvedValue({ data: {} });
    render(<WebhookMonitorPage />);
    expect(await screen.findByText('Development mock — test only')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Quarantine' }));
    await screen.findByText('Callback quarantined for operator review.');
    expect(mockClient.post).toHaveBeenCalledWith('/v1/admin/webhooks/deliveries/receipt-1/quarantine', {});
});

test('quarantined receipts are visible and retain a retry action', async () => {
    mockClient.get.mockResolvedValue({ data: [{ id: 'receipt-1', event: 'payment.success', endpoint: '/v1/webhooks/test', status: 'failed', attempts: 1, last_attempt_at: null, quarantined: true }], meta });
    render(<WebhookMonitorPage />);
    expect(await screen.findByText('Quarantined')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Retry' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Quarantine' })).not.toBeInTheDocument();
});
