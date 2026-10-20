import { test, expect, type BrowserContext, type Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
const out = path.resolve(__dirname, '../../../../specs/admin-browser-validation/fixture-screenshots/p1023-security');
const perms = ['security.config.read', 'security.config.write', 'configuration.approve', 'security.findings.read', 'security.findings.write', 'security.events.read', 'security.events.export'];
async function session(context: BrowserContext, permissions = perms) { const token = 'e30.' + Buffer.from(JSON.stringify({ sub: 'checker', permissions })).toString('base64url') + '.test-only'; await context.addCookies([{ name: 'admin_token', value: token, url: 'http://localhost:3030' }, { name: 'admin_user', value: encodeURIComponent(JSON.stringify({ id: 'checker', name: 'Security administrator', email: 'security@example.test' })), url: 'http://localhost:3030' }, { name: 'admin_sid', value: 'security-session', url: 'http://localhost:3030' }]); }
async function fixture(page: Page) { const calls: string[] = []; let policy = { key: 'auth.password.min_length', label: 'Minimum password length', type: 'integer', value: 12, configured: true, last_modified_by: 'checker', last_modified_at: '2026-10-16T00:00:00Z', pending: null as null | {
        id: string;
        maker_id: string;
        value: number;
    } }; let row = { id: 'finding-1', title: 'Dependency vulnerability', component: 'API dependency', severity: 'high', description: 'Upgrade the affected package.', cvss_score: '8.1', remediation_steps: 'Upgrade and run regression tests.', status: 'open', version: 1, timeline: [{ id: 'detected', action: 'detected', status: 'open', actor_id: null, occurred_at: '2026-10-16T00:00:00Z' }] }; await page.route('**/v1/**', async (route) => { const r = route.request(), u = new URL(r.url()); let data: unknown = {}; if (u.pathname.includes('/security/findings/')) {
    if (r.method() === 'PATCH') {
        const b = r.postDataJSON();
        calls.push(r.headers()['idempotency-key'] ?? '');
        row = { ...row, ...b, version: row.version + 1, timeline: [...row.timeline, { id: 'changed', action: 'updated', status: b.status ?? row.status, actor_id: 'checker', occurred_at: '2026-10-16T01:00:00Z' }] };
    }
    data = row;
}
else if (u.pathname.includes('/security-config')) {
    if (r.method() === 'PATCH') {
        calls.push(r.headers()['idempotency-key'] ?? '');
        policy = { ...policy, pending: { id: 'proposal-1', maker_id: 'maker', value: r.postDataJSON().value } };
    }
    if (r.method() === 'POST') {
        calls.push(r.headers()['idempotency-key'] ?? '');
        policy = { ...policy, value: policy.pending?.value ?? 12, pending: null };
    }
    data = { settings: [policy], roles: [] };
}
else if (u.pathname.includes('/security/events/export')) {
    await route.fulfill({ contentType: 'application/x-ndjson', body: JSON.stringify({ schema_version: 1, action: 'identity.login.failed' }) + '\n' });
    return;
}
else if (u.pathname.includes('/security/events')) {
    data = { items: [{ id: 'event-1', source: 'audit', event_type: 'login_failed', severity: 'medium', occurred_at: '2026-10-16T00:00:00Z', actor_id: null, action: 'identity.login.failed', outcome: 'failure', correlation_id: 'correlation-1' }], next_cursor: null };
} await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data }) }); }); return calls; }
async function shot(page: Page, name: string) { fs.mkdirSync(out, { recursive: true }); await page.evaluate(() => document.querySelectorAll('*').forEach(el => { el.scrollTop = 0; })); await page.screenshot({ path: path.join(out, name), fullPage: true }); }
test('policy provenance and responsive layout', async ({ page, context }) => { await session(context); await fixture(page); await page.goto('/platform/security-config'); await expect(page.getByText('Minimum password length', { exact: true })).toBeVisible(); await page.getByRole('button', { name: 'Edit Minimum password length' }).click(); await page.getByLabel('Policy value').fill('14'); await page.getByRole('button', { name: 'Submit policy for approval' }).click(); await expect(page.getByText('Proposed: 14')).toBeVisible(); await page.getByRole('button', { name: 'Approve Minimum password length' }).click(); await expect(page.getByRole('status')).toHaveText('Approved policy applied.'); await shot(page, 'policies-desktop.png'); await page.setViewportSize({ width: 390, height: 844 }); await shot(page, 'policies-mobile.png'); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true); });
test('finding investigation and audited remediation', async ({ page, context }) => { await session(context); const calls = await fixture(page); await page.goto('/security/findings/finding-1'); await expect(page.getByRole('heading', { name: 'Dependency vulnerability' })).toBeVisible(); await page.getByRole('button', { name: 'Start remediation' }).click(); await expect(page.getByRole('status')).toHaveText('Finding updated and audited.'); expect(calls[0]).toMatch(/^[0-9a-f-]{36}$/); await page.getByRole('button', { name: 'Resolve finding' }).click(); await page.getByRole('button', { name: 'Verify resolution' }).click(); await expect(page.getByRole('button', { name: 'Verify resolution' })).toHaveCount(0); await shot(page, 'finding-desktop.png'); await page.setViewportSize({ width: 390, height: 844 }); await shot(page, 'finding-mobile.png'); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true); });
test('security events filters and authenticated SIEM download', async ({ page, context }) => { await session(context); await fixture(page); await page.goto('/security/events'); await expect(page.getByText('identity.login.failed')).toBeVisible(); await page.getByLabel('Security event severity').selectOption('medium'); await page.getByLabel('Security event type').selectOption('login_failed'); const download = page.waitForEvent('download'); await page.getByRole('button', { name: 'Export SIEM JSONL' }).click(); expect((await download).suggestedFilename()).toBe('security-events.ndjson'); await shot(page, 'events-desktop.png'); await page.setViewportSize({ width: 390, height: 844 }); await shot(page, 'events-mobile.png'); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true); await page.keyboard.press('Tab'); expect(await page.evaluate(() => document.activeElement?.tagName)).not.toBe('BODY'); });
test('security events route requires permission', async ({ page, context }) => { await session(context, []); await fixture(page); await page.goto('/security/events'); await expect(page).toHaveURL(/\/403/); });
