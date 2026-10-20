import { test, expect, type BrowserContext, type Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
const output = path.resolve(__dirname, '../../../../specs/044-integration-api-platform-management/screenshots');
const id = '019c1111-1111-7111-8111-111111111111', clientId = '019c2222-2222-7222-8222-222222222222', eventId = '019c3333-3333-7333-8333-333333333333';
async function session(context: BrowserContext, permissions = ['integrations.manage']) { const token = 'e30.' + Buffer.from(JSON.stringify({ sub: clientId, permissions })).toString('base64url') + '.test-only'; await context.addCookies([{ name: 'admin_token', value: token, url: 'http://localhost:3020' }, { name: 'admin_user', value: encodeURIComponent(JSON.stringify({ id: clientId, name: 'Integration operator', email: 'operator@example.test' })), url: 'http://localhost:3020' }, { name: 'admin_sid', value: 'test-session', url: 'http://localhost:3020' }]); }
async function mockApi(page: Page) {
    let created = false, status = 'active', deliveryStatus = 'failed';
    const queries: string[] = [];
    const partner = { id, display_name: 'Sandbox messaging partner', provider_type: 'sms', provider_slug: 'sandbox', health: 'degraded', last_checked_at: '2026-10-15T06:00:00Z', status: 'active', is_default: true };
    const client = () => ({ id: clientId, name: 'Partner reporting app', api_key_prefix: 'test-key', status, permissions: ['integrations.manage'], ip_whitelist: ['192.0.2.0/24'], integration_id: id, rate_limit_per_minute: 60, last_used_at: null });
    await page.route('**/v1/**', async (route) => {
        const req = route.request(), url = new URL(req.url());
        let data: unknown = [];
        const meta: unknown = { current_page: 1, last_page: 1, total: 1 };
        if (url.pathname.includes('/api-clients')) {
            if (req.method() === 'POST') {
                created = true;
                data = { ...client(), api_key: 'TEST-ONLY-API-KEY', raw_secret: 'TEST-ONLY-ONE-TIME-SECRET' };
            }
            else if (req.method() === 'DELETE') {
                status = 'revoked';
                data = client();
            }
            else
                data = created ? [client()] : [];
        }
        else if (url.pathname.endsWith('/health')) {
            data = { integration_id: id, display_name: partner.display_name, health: 'degraded', last_checked_at: partner.last_checked_at, sample_count: 48, buckets: Array.from({ length: 24 }, (_, i) => ({ hour: new Date(Date.UTC(2026, 9, 14, 7 + i)).toISOString(), samples: i === 4 ? 0 : 2, uptime_percent: i === 4 ? null : i === 22 ? 50 : 100 })), recent_errors: [{ timestamp: '2026-10-15T05:40:00Z', status: 'degraded', message: 'Provider probe timed out' }] };
        }
        else if (url.pathname.includes('/providers'))
            data = [partner];
        else if (url.pathname.endsWith('/retry')) {
            deliveryStatus = 'retrying';
            data = { id: eventId, status: deliveryStatus };
        }
        else if (url.pathname.endsWith('/deliveries')) {
            queries.push(url.search);
            data = url.searchParams.get('status') === 'failed' && deliveryStatus !== 'failed' ? [] : [{ id: eventId, event: 'payment.completed', provider_slug: 'sandbox', endpoint: '/v1/webhooks/sandbox', status: deliveryStatus, attempts: 3, last_attempt_at: '2026-10-15T05:00:00Z' }];
        }
        await route.fulfill({ status: req.method() === 'POST' && url.pathname.endsWith('/api-clients') ? 201 : 200, contentType: 'application/json', headers: { 'Cache-Control': 'no-store' }, body: JSON.stringify({ data, meta }) });
    });
    return queries;
}
test.beforeAll(() => fs.mkdirSync(output, { recursive: true }));
test('partner client create secret once rotate and revoke', async ({ page, context }) => {
    await session(context);
    await mockApi(page);
    await page.goto('/integrations/providers');
    await page.getByRole('link', { name: 'New API Client', exact: true }).click();
    await page.getByLabel('Client name', { exact: true }).fill('Partner reporting app');
    await page.getByLabel('Permissions', { exact: true }).fill('integrations.manage');
    await page.getByLabel('IP whitelist', { exact: true }).fill('192.0.2.0/24');
    await page.getByRole('button', { name: 'Create client', exact: true }).click();
    await expect(page.getByLabel('Client secret', { exact: true })).toHaveValue('TEST-ONLY-ONE-TIME-SECRET');
    await page.screenshot({ path: path.join(output, 'credentials-once.png'), fullPage: true, mask: [page.getByLabel('Client secret', { exact: true }), page.getByLabel('API key', { exact: true })] });
    await page.getByRole('button', { name: 'I have saved the credentials' }).click();
    await expect(page.getByLabel('Client secret', { exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Rotate', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm rotate' }).click();
    await expect(page.getByLabel('Client secret', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'I have saved the credentials' }).click();
    await page.getByRole('button', { name: 'Revoke', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm revoke' }).click();
    await expect(page.getByRole('status', { name: 'Client action status' })).toContainText('revoked');
    await expect(page.getByRole('cell', { name: 'revoked', exact: true })).toBeVisible();
    expect(await page.evaluate(() => localStorage.length)).toBe(0);
});
test('callback filters retry and responsive log', async ({ page, context }) => {
    await session(context);
    const queries = await mockApi(page);
    await page.goto('/integrations/webhooks');
    await page.getByLabel('Webhook status').selectOption('failed');
    await page.getByLabel('Event type', { exact: true }).fill('payment.completed');
    await expect.poll(() => queries.some(q => q.includes('status=failed') && q.includes('event_type=payment.completed'))).toBeTruthy();
    await expect(page.getByRole('button', { name: 'Retry', exact: true })).toBeVisible();
    await page.screenshot({ path: path.join(output, 'webhooks-desktop.png'), fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: path.join(output, 'webhooks-mobile.png'), fullPage: true });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
    await page.getByRole('button', { name: 'Retry', exact: true }).click();
    await expect(page.getByRole('status', { name: 'Webhook action status' })).toContainText('queued');
    await expect(page.getByText('No callbacks match these filters.')).toBeVisible();
});
test('provider health chart errors keyboard data and permission gate', async ({ page, context }) => {
    await session(context);
    await mockApi(page);
    await page.goto('/integrations');
    await expect(page.getByText('Degraded', { exact: true })).toBeVisible();
    await page.screenshot({ path: path.join(output, 'providers-desktop.png'), fullPage: true });
    await page.getByRole('link', { name: 'View Health' }).click();
    await expect(page.getByRole('img', { name: '24-hour observed uptime chart' })).toBeVisible();
    await expect(page.getByText('Provider probe timed out')).toBeVisible();
    await page.screenshot({ path: path.join(output, 'health-desktop.png'), fullPage: true });
    await page.getByText('View chart data', { exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.getByRole('columnheader', { name: 'Observed uptime' })).toBeVisible();
    await session(context, []);
    await page.goto('/integrations/providers');
    await expect(page).toHaveURL(/403/);
});
