import { test, expect, type BrowserContext, type Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
const out = path.resolve(__dirname, '../../../../specs/045-support-dispute-management/screenshots');
const id = '019c1111-1111-7111-8111-111111111111', user = '019c2222-2222-7222-8222-222222222222';
const permissions = ['support.investigate', 'support.assign', 'support.resolve', 'dispute.refund', 'dispute.escalate'];
async function session(context: BrowserContext, perms = permissions) { const token = 'e30.' + Buffer.from(JSON.stringify({ sub: user, permissions: perms })).toString('base64url') + '.test-only'; await context.addCookies([{ name: 'admin_token', value: token, url: 'http://localhost:3021' }, { name: 'admin_user', value: encodeURIComponent(JSON.stringify({ id: user, name: 'Support operator', email: 'support@example.test' })), url: 'http://localhost:3021' }, { name: 'admin_sid', value: 'support-session', url: 'http://localhost:3021' }]); }
async function mock(page: Page) {
    const calls: {
        path: string;
        body: Record<string, unknown>;
        key: string | undefined;
    }[] = [];
    const queries: string[] = [];
    let row = { id, number: 'DSP-000021', customer_id: user, type: 'Dispute', priority: 'high', status: 'In Progress', raw_status: 'approved', created_at: '2026-10-14T10:00:00Z', assigned_id: user, deadline: '2026-10-15T10:00:00Z', overdue: true, description: 'Customer reports a duplicate merchant charge. Review transaction evidence before refunding.', transaction_ids: [id], timeline: [{ id: 'note-existing', kind: 'note', actor_id: user, created_at: '2026-10-15T05:00:00Z', payload: { content: 'Merchant receipt received; checking duplicate posting.', internal: true } }], evidence: [{ id: 'evidence-1', filename: 'merchant-receipt.pdf', legacy: true }] };
    await page.route('**/v1/**', async (route) => {
        const req = route.request(), url = new URL(req.url());
        let data: unknown = {};
        const body = req.method() === 'GET' ? {} : req.postDataJSON() as Record<string, unknown>;
        calls.push({ path: url.pathname, body, key: req.headers()['idempotency-key'] });
        if(url.pathname.includes('/attachments/')) {
            expect(req.headers()['authorization']).toContain('Bearer e30.');
            expect(req.headers()['x-correlation-id']).toMatch(/^[0-9a-f-]{36}$/);
            await route.fulfill({status:200,contentType:'application/pdf',body:'%PDF-1.4\n%%EOF'});
            return;
        }
        if (url.pathname.endsWith('/step-up/initiate'))
            data = { step_up_required: true, step_up_token: 'test-challenge', methods: ['totp'] };
        else if (url.pathname.endsWith('/step-up/verify'))
            data = { step_up_access_token: 'test-proof', scope: 'support.refund:' + id };
        else if (url.pathname.endsWith('/refund')) {
            row = { ...row, status: 'Resolved', raw_status: 'refunded' };
            data = row;
        }
        else if (req.method() === 'PATCH') {
            if (body['action'] === 'note')
                row = { ...row, timeline: [...row.timeline, { id: 'new-note', kind: 'note', actor_id: user, created_at: '2026-10-15T06:00:00Z', payload: { content: String(body['content']), internal: true } }] };
            if (body['action'] === 'escalate')
                row = { ...row, status: 'Escalated', deadline: '2026-10-16T06:00:00Z', overdue: false };
            data = row;
        }
        else if (url.pathname.endsWith('/cases')) {
            queries.push(url.search);
            data = { items: [row], page: 1, last_page: 1, total: 1 };
        }
        else
            data = row;
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data }) });
    });
    return { calls, queries };
}
test.beforeAll(() => fs.mkdirSync(out, { recursive: true }));
test('queue filters detail notes escalation and narrow layout', async ({ page, context }) => { await session(context); const { calls, queries } = await mock(page); await page.goto('/support/cases'); await expect(page.getByText('Overdue', { exact: true })).toBeVisible(); await page.getByLabel('Type', { exact: true }).selectOption('Dispute'); await page.getByLabel('Priority', { exact: true }).selectOption('high'); await expect.poll(() => queries.some(q => q.includes('type=Dispute') && q.includes('priority=high'))).toBeTruthy(); await page.screenshot({ path: path.join(out, 'queue-desktop.png'), fullPage: true }); await page.getByRole('link', { name: 'DSP-000021', exact: true }).click(); await expect(page.getByText('merchant-receipt.pdf', { exact: true })).toBeVisible(); const download=page.waitForEvent('download'); await page.getByRole('button',{name:'merchant-receipt.pdf',exact:true}).click(); expect((await download).suggestedFilename()).toBe('merchant-receipt.pdf'); await page.getByLabel('Internal note', { exact: true }).fill('Reviewed customer receipt and transaction history.'); await page.getByRole('button', { name: 'Add internal note', exact: true }).click(); await expect(page.getByText('Reviewed customer receipt and transaction history.', { exact: true })).toBeVisible(); await page.getByLabel('Support agent ID', { exact: true }).fill(user); await page.getByLabel('Escalation reason', { exact: true }).selectOption('financial_review'); await page.getByRole('button', { name: 'Escalate case', exact: true }).click(); await expect(page.getByRole('status', { name: 'Case action status' })).toContainText('24-hour'); expect(calls.some(c => c.body['action'] === 'escalate' && c.body['reason'] === 'financial_review')).toBeTruthy(); await page.evaluate(()=>{document.querySelectorAll('*').forEach(el=>{if(el.scrollTop>0)el.scrollTop=0;});}); await page.screenshot({ path: path.join(out, 'case-desktop.png'), fullPage: true }); await page.setViewportSize({ width: 390, height: 844 }); await page.evaluate(()=>{document.querySelectorAll('*').forEach(el=>{if(el.scrollTop>0)el.scrollTop=0;});}); await page.screenshot({ path: path.join(out, 'case-mobile.png'), fullPage: true }); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy(); await page.getByLabel('Internal note', { exact: true }).focus(); await expect(page.getByLabel('Internal note', { exact: true })).toBeFocused(); });
test('refund requires MFA confirmation and stable financial request', async ({ page, context }) => { await session(context); const { calls } = await mock(page); await page.goto('/support/cases/' + id); await page.getByLabel('Amount (minor units)', { exact: true }).fill('500'); await page.getByLabel('Refund reason', { exact: true }).fill('Verified duplicate charge; partial refund.'); await page.getByRole('button', { name: 'Initiate Refund', exact: true }).click(); expect(calls.some(c => c.path.endsWith('/refund'))).toBeFalsy(); await page.getByLabel('Verification code', { exact: true }).fill('123456'); await page.screenshot({ path: path.join(out, 'refund-confirmation.png'), fullPage: true, mask: [page.getByLabel('Verification code', { exact: true })] }); await page.getByRole('button', { name: 'Verify and confirm refund', exact: true }).click(); await expect(page.getByRole('status', { name: 'Case action status' })).toContainText('Refund submitted'); const c = calls.find(c => c.path.endsWith('/refund')); expect(c?.body['step_up_token']).toBe('test-proof'); expect(c?.body['amount_minor']).toBe(500); expect(c?.key).toMatch(/^[0-9a-f-]{36}$/); expect(await page.evaluate(() => localStorage.length)).toBe(0); await expect(page.getByRole('button', { name: 'Initiate Refund', exact: true })).toHaveCount(0); });
test('permission gate prevents case fetch', async ({ page, context }) => { await session(context, ['support.create']); const { calls } = await mock(page); await page.goto('/support/cases'); await expect(page).toHaveURL(/\/403/); expect(calls.filter(c => c.path.includes('/support/'))).toHaveLength(0); });
