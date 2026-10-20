import { test, expect, type BrowserContext, type Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
const output = path.resolve(__dirname, '../../../../specs/admin-browser-validation/fixture-screenshots/p1018-security');
const id = '019c1111-1111-7111-8111-111111111111';
const maker = '019c2222-2222-7222-8222-222222222222';
const checker = '019c3333-3333-7333-8333-333333333333';
const related = '019c4444-4444-7444-8444-444444444444';
const permissions = ['security.alerts.read', 'risk.manage_alerts', 'risk.freeze_account', 'security.findings.read', 'security.findings.write'];
async function session(context: BrowserContext, actor = maker, perms = permissions) {
    const token = 'e30.' + Buffer.from(JSON.stringify({ sub: actor, permissions: perms })).toString('base64url') + '.test-only';
    await context.addCookies([{ name: 'admin_token', value: token, url: 'http://localhost:3030' }, { name: 'admin_user', value: encodeURIComponent(JSON.stringify({ id: actor, name: 'Test risk analyst', email: 'analyst@example.test' })), url: 'http://localhost:3030' }, { name: 'admin_sid', value: 'test-session', url: 'http://localhost:3030' }]);
}
function fixture() {
    return { id, title: 'Suspicious transfer pattern', description: 'Velocity threshold exceeded', severity: 'high', alert_type: 'threshold_breach', subject_type: 'customer', subject_id: '019c5555-5555-7555-8555-555555555555', created_at: '2026-10-15T03:00:00Z', status: 'open', assigned_to: null as string | null, version: 1,
        trigger: { description: 'Three transfers exceeded the configured velocity rule.', events: [] },
        affected_transactions: [{ reference: 'TX-AML-2026-001', detected_at: '2026-10-15T03:00:00Z' }],
        entity_history: [{ id: 'event-1', event_type: 'velocity_threshold', severity: 'high', detected_at: '2026-10-15T03:00:00Z' }],
        risk_timeline: [{ id: 'eval-1', score: 85, risk_level: 'high', evaluated_at: '2026-10-15T03:00:00Z' }],
        notes: [] as {
            id: string;
            actor_id: string;
            note: string;
            created_at: string;
        }[],
        related_alerts: [] as {
            id: string;
            title: string;
        }[], freeze_requests: [] as {
            id: string;
            maker_id: string;
            reason: string;
            status: string;
        }[],
        active_hold: null as null | {
            id: string;
            placed_at: string;
        }, decisions: [] };
}
async function mockApi(page: Page, a = fixture()) {
    let findingStatus = 'open';
    const queries: string[] = [];
    await page.route('**/v1/**', async (route) => {
        const request = route.request(), url = new URL(request.url()), p = url.pathname;
        const body = request.method() === 'GET' ? {} : request.postDataJSON() ?? {};
        let data: unknown = {};
        let meta: unknown = undefined;
        if (p.includes('/security/alerts')) {
            if (request.method() === 'GET' && p.endsWith('/alerts')) {
                queries.push(url.search);
                data = [a];
                meta = { last_page: 1, current_page: 1, total: 1 };
            }
            else if (request.method() === 'GET')
                data = a;
            else {
                if (p.endsWith('/assign'))
                    a.assigned_to = maker;
                if (p.endsWith('/notes'))
                    a.notes.push({ id: 'note-1', actor_id: maker, note: body.note, created_at: '2026-10-15T03:10:00Z' });
                if (p.endsWith('/links'))
                    a.related_alerts.push({ id: body.related_alert_id, title: 'Related device alert' });
                if (p.endsWith('/resolve'))
                    a.status = body.outcome === 'false_positive' ? 'resolved_false_positive' : 'resolved_true_positive';
                if (p.endsWith('/escalate'))
                    a.status = 'escalated';
                if (p.endsWith('/freeze-requests'))
                    a.freeze_requests.push({ id: 'request-1', maker_id: maker, reason: body.reason, status: 'pending' });
                if (p.endsWith('/approve')) {
                    a.active_hold = { id: 'hold-1', placed_at: '2026-10-15T03:15:00Z' };
                    a.freeze_requests[0]!.status = 'approved';
                }
                a.version++;
                data = a;
            }
        }
        else if (p.endsWith('/security/dashboard'))
            data = { total_open: 1, findings_by_status: { open: 1, acknowledged: 0, in_remediation: 0, resolved: 0, suppressed: 0 }, findings_by_severity: { critical: 1, high: 0, medium: 0, low: 0, info: 0 } };
        else if (p.includes('/security/findings')) {
            if (request.method() === 'PATCH')
                findingStatus = body.status;
            data = request.method() === 'GET' ? [{ id: 'finding-1', cve_id: 'CVE-2026-12345', severity: 'critical', component: 'Payment gateway dependency', title: 'Dependency requires update', status: findingStatus, detected_at: '2026-10-15T02:00:00Z' }] : {};
            meta = { last_page: 1, current_page: 1, total: 1 };
        }
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, data, meta, error: null }) });
    });
    return { a, queries };
}
test.beforeAll(() => fs.mkdirSync(output, { recursive: true }));
test('risk queue and complete investigation workflow', async ({ page, context }) => {
    await session(context);
    const { queries } = await mockApi(page);
    await page.goto('/security/dashboard');
    await expect(page.getByRole('link', { name: 'Suspicious transfer pattern' })).toBeVisible();
    await page.getByLabel('Alert severity').selectOption('high');
    await expect.poll(() => queries.some(q => q.includes('severity=high'))).toBeTruthy();
    await page.screenshot({ path: path.join(output, 'queue-desktop.png'), fullPage: true });
    await page.getByRole('link', { name: 'Suspicious transfer pattern' }).click();
    await expect(page.getByRole('link', { name: 'TX-AML-2026-001' })).toBeVisible();
    await page.getByRole('button', { name: 'Assign to me' }).click();
    await expect(page.getByRole('button', { name: 'Assign to me' })).toBeDisabled();
    await page.getByLabel('Investigation note').fill('Device and transaction records checked.');
    await page.getByRole('button', { name: 'Add note' }).click();
    await expect(page.getByText('Device and transaction records checked.')).toBeVisible();
    await page.getByLabel('Related alert ID').fill(related);
    await page.getByRole('button', { name: 'Link alert' }).click();
    await expect(page.getByRole('link', { name: 'Related device alert' })).toBeVisible();
    await page.screenshot({ path: path.join(output, 'investigation-desktop.png'), fullPage: true });
    await page.getByRole('button', { name: 'Escalate', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm escalation' }).click();
    await expect(page.getByRole('button', { name: 'Escalate', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Resolve alert' }).click();
    await page.getByLabel('Outcome', { exact: true }).selectOption('confirmed_fraud');
    await page.getByLabel('Resolution reason').fill('Confirmed suspicious transactions from evidence.');
    await page.getByRole('button', { name: 'Confirm resolution' }).click();
    await expect(page.getByRole('button', { name: 'Resolve alert' })).toBeDisabled();
});
test('freeze dialog keyboard flow and separate checker approval', async ({ page, context }) => {
    await session(context);
    const state = await mockApi(page);
    await page.goto('/security/alerts/' + id);
    await page.getByRole('button', { name: 'Freeze Account' }).click();
    await expect(page.getByRole('button', { name: 'Request freeze' })).toBeDisabled();
    await page.getByLabel('Freeze reason').fill('Confirmed suspicious transfer requires investigation.');
    await page.getByLabel('Freeze reason').pressSequentially(' Verified.');
    await expect(page.getByLabel('Freeze reason')).toBeFocused();
    await page.getByLabel('Freeze reason').press('Tab');
    await expect(page.getByRole('button', {name:'Cancel',exact:true})).toBeFocused();
    await page.screenshot({ path: path.join(output, 'freeze-confirmation.png'), fullPage: true });
    await page.getByRole('button', { name: 'Request freeze' }).click();
    await expect(page.getByText('Pending freeze approval')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Approve freeze' })).toHaveCount(0);
    await session(context, checker);
    await page.reload();
    await page.getByRole('button', { name: 'Approve freeze' }).click();
    await expect(page.getByText(/Account is Frozen/)).toBeVisible();
    expect(state.a.active_hold).not.toBeNull();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: path.join(output, 'investigation-mobile.png'), fullPage: true });
});
test('findings lifecycle overview and denied route', async ({ page, context }) => {
    await session(context);
    await mockApi(page);
    await page.goto('/security/findings');
    await expect(page.getByText('CVE-2026-12345')).toBeVisible();
    await page.getByRole('button', { name: 'Acknowledge' }).click();
    await page.getByRole('button', { name: 'Start remediation' }).click();
    await page.screenshot({ path: path.join(output, 'findings-desktop.png'), fullPage: true });
    await page.getByRole('button', { name: 'Resolve', exact: true }).click();
    await expect(page.getByRole('cell', { name: 'Resolved', exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Findings overview' }).click();
    await expect(page.getByRole('heading', { name: 'Findings by severity' })).toBeVisible();
    await session(context, maker, []);
    await page.goto('/security/dashboard');
    await expect(page).toHaveURL(/\/403/);
});
