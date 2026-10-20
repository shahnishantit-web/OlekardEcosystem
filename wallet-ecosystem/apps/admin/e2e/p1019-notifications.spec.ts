import { test, expect, type BrowserContext, type Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
const output = path.resolve(__dirname, '../../../../specs/043-notification-management/screenshots');
const id = '019c1111-1111-7111-8111-111111111111', actor = '019c2222-2222-7222-8222-222222222222';
const permissions = ['notifications.templates.read', 'notifications.templates.write', 'notifications.log.read', 'notifications.retry', 'notifications.rules.read', 'notifications.rules.write'];
async function session(context: BrowserContext, perms = permissions) { const token = 'e30.' + Buffer.from(JSON.stringify({ sub: actor, permissions: perms })).toString('base64url') + '.test-only'; await context.addCookies([{ name: 'admin_token', value: token, url: 'http://localhost:3019' }, { name: 'admin_user', value: encodeURIComponent(JSON.stringify({ id: actor, name: 'Notification operator', email: 'operator@example.test' })), url: 'http://localhost:3019' }, { name: 'admin_sid', value: 'test-session', url: 'http://localhost:3019' }]); }
async function mockApi(page: Page) {
    let template = { id, key: 'operations.service_notice', category: 'operational', language: 'en', revision: 1, subject_template: 'Hello {{name}}', body_template: 'Service notice: {{reference}}', supported_channels: ['email', 'sms', 'push'], variables: ['name', 'reference'], active: true };
    const rules = [{ template_id: id, event: 'operations.service_notice', channel: 'email', enabled: true, mandatory: false, revision: 0 }, { template_id: actor, event: 'security.suspicious_activity', channel: 'email', enabled: true, mandatory: true, revision: 0 }];
    let status = 'failed';
    const queries: string[] = [];
    await page.route('**/v1/**', async (route) => {
        const req = route.request(), url = new URL(req.url()), body = req.method() === 'GET' ? {} : req.postDataJSON() ?? {};
        let data: unknown = [], meta: unknown;
        if (url.pathname.endsWith('/templates/preview')) {
            const sub = (s: string) => s.replace(/{{(\w+)}}/g, (_m, k) => body.variables[k] ?? '[Unknown]');
            data = { subject: sub(body.subject_template), body: sub(body.body_template) };
        }
        else if (url.pathname.endsWith('/templates/test'))
            data = { id, status: 'queued' };
        else if (url.pathname.endsWith('/templates')) {
            if (req.method() === 'PATCH')
                template = { ...template, ...body, revision: template.revision + 1 };
            data = req.method() === 'GET' ? [template] : template;
        }
        else if (url.pathname.endsWith('/log')) {
            queries.push(url.search);
            data = url.searchParams.get('status') !== null && url.searchParams.get('status') !== status ? [] : [{ id, recipient: actor, channel: 'email', event: 'SERVICE_NOTICE', status, timestamp: '2026-10-15T03:00:00Z', attempt_count: 2 }];
            meta = { last_page: 1, current_page: 1, total: 1 };
        }
        else if (url.pathname.includes('/retry/')) {
            status = 'queued';
            data = { id, status };
        }
        else if (url.pathname.endsWith('/rules')) {
            if (req.method() === 'PATCH') {
                rules[0]!.enabled = body.enabled;
                rules[0]!.revision++;
            }
            data = req.method() === 'GET' ? rules : rules[0];
        }
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data, meta }) });
    });
    return queries;
}
test.beforeAll(() => fs.mkdirSync(output, { recursive: true }));
test('templates edit preview keyboard and self-test', async ({ page, context }) => {
    await session(context);
    await mockApi(page);
    await page.goto('/notifications/templates');
    await page.getByRole('button', { name: 'Edit operations.service_notice email' }).click();
    await expect(page.getByLabel('Subject', { exact: true })).toBeFocused();
    await page.getByLabel('Subject', { exact: true }).fill('Notice for {{name}}');
    await page.getByLabel('name', { exact: true }).fill('Ada');
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Template preview' })).toContainText('Notice for Ada');
    await page.getByRole('region', { name: 'Template preview' }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(output, 'template-preview.png'), fullPage: true });
    await page.getByRole('button', { name: 'Save template' }).click();
    await expect(page.getByRole('status')).toContainText('Template saved.');
    await page.getByRole('button', { name: 'Send test to me' }).click();
    await expect(page.getByRole('status')).toContainText('own account');
    await page.screenshot({ path: path.join(output, 'templates-desktop.png'), fullPage: true });
    await page.getByLabel('Body', { exact: true }).focus();
    await page.keyboard.press('Tab');
    await expect(page.getByLabel('Language tag')).toBeFocused();
});
test('delivery filters retry and narrow layout', async ({ page, context }) => {
    await session(context);
    const queries = await mockApi(page);
    await page.goto('/notifications/log');
    await page.getByLabel('Channel', { exact: true }).selectOption('email');
    await page.getByLabel('Status', { exact: true }).selectOption('failed');
    await page.getByLabel('From date (UTC)').fill('2026-10-15');
    await page.getByLabel('To date (UTC)').fill('2026-10-15');
    await expect.poll(() => queries.some(q => q.includes('channel=email') && q.includes('status=failed') && q.includes('to=2026-10-15'))).toBeTruthy();
    await expect(page.getByRole('button', {name:'Retry',exact:true})).toBeVisible();
    await page.screenshot({path:path.join(output,'log-desktop.png'),fullPage:true});
    await page.setViewportSize({width:390,height:844});
    await page.screenshot({path:path.join(output,'log-mobile.png'),fullPage:true});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();
    await page.setViewportSize({width:1440,height:1000});
    await page.getByRole('button', { name: 'Retry', exact: true }).click();
    await expect(page.getByRole('status', {name:'Delivery action status'})).toContainText('Retry queued.');
    await expect(page.getByRole('button', { name: 'Retry', exact: true })).toHaveCount(0);
    await expect(page.getByText('No deliveries match these filters.')).toBeVisible();
    await page.setViewportSize({width:390,height:844});
    await page.screenshot({path:path.join(output,'log-empty-mobile.png'),fullPage:true});
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
});
test('optional rules toggle mandatory locked and route denied', async ({ page, context }) => {
    await session(context);
    await mockApi(page);
    await page.goto('/notifications/rules');
    await expect(page.getByRole('switch', { name: 'security.suspicious_activity email' })).toBeDisabled();
    await page.getByRole('switch', { name: 'operations.service_notice email' }).click();
    await expect(page.getByRole('switch', { name: 'operations.service_notice email' })).toHaveAttribute('aria-checked', 'false');
    await page.screenshot({ path: path.join(output, 'rules-desktop.png'), fullPage: true });
    await session(context, []);
    await page.goto('/notifications/templates');
    await expect(page).toHaveURL(/403/);
});
