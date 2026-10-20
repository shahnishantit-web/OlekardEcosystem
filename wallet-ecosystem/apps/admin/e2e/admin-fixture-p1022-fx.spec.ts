import { test, expect, type BrowserContext, type Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
const out = path.resolve(__dirname, '../../../../specs/admin-browser-validation/fixture-screenshots/p1022-fx');
const perms = ['multi_currency.manage_currencies', 'multi_currency.configure_rate', 'configuration.approve'];
async function session(context: BrowserContext, permissions = perms) { const token = 'e30.' + Buffer.from(JSON.stringify({ sub: 'checker', permissions })).toString('base64url') + '.test-only'; await context.addCookies([{ name: 'admin_token', value: token, url: 'http://localhost:3030' }, { name: 'admin_user', value: encodeURIComponent(JSON.stringify({ id: 'checker', name: 'FX administrator', email: 'fx@example.test' })), url: 'http://localhost:3030' }, { name: 'admin_sid', value: 'fx-session', url: 'http://localhost:3030' }]); }
const rate = { id: 'rate-1', from_currency_code: 'BWP', to_currency_code: 'USD', rate: '0.07500000', spread: '0.01000', source: 'manual', provider: null, status: 'active', last_updated: '2026-10-15T10:00:00Z', effective_from: '2026-10-15T10:00:00Z', created_by: 'maker' };
async function fixture(page: Page) {
    const calls: {
        path: string;
        body: unknown;
        key: string | undefined;
    }[] = [];
    let currency = { code: 'USD', name: 'US Dollar', decimal_places: 2, is_active: true, is_base: false, pending_change_request_id: null as string | null, pending_is_active: null as boolean | null };
    await page.route('**/v1/**', async (route) => {
        const req = route.request(), url = new URL(req.url());
        const body = req.method() === 'GET' ? {} : req.postDataJSON() as Record<string, unknown>;
        let data: unknown = {};
        let status = 200;
        if (url.pathname.includes('/currencies')) {
            if (req.method() === 'PATCH') {
                calls.push({ path: url.pathname, body, key: req.headers()['idempotency-key'] });
                currency = { ...currency, pending_change_request_id: 'request-1', pending_is_active: false };
            }
            if (req.method() === 'POST') {
                calls.push({ path: url.pathname, body, key: req.headers()['idempotency-key'] });
                currency = { ...currency, is_active: false, pending_change_request_id: null, pending_is_active: null };
            }
            data = req.method() === 'GET' ? [{ ...currency, code: 'BWP', name: 'Botswana Pula', is_active: true, is_base: true, pending_change_request_id: null, pending_is_active: null }, currency] : currency;
        }
        else if (url.pathname.includes('/history'))
            data = { chart: [{ ...rate, id: 'old', source: 'provider', provider: 'Approved provider', rate: '0.07300000', effective_from: '2026-10-01T10:00:00Z' }, rate], history: [rate, { ...rate, id: 'old', source: 'provider', provider: 'Approved provider', rate: '0.07300000', effective_from: '2026-10-01T10:00:00Z' }], pagination: { page: 1, last_page: 1, total: 2 } };
        else if (req.method() === 'POST') {
            calls.push({ path: url.pathname, body, key: req.headers()['idempotency-key'] });
            if ((body as {
                mode?: string;
            }).mode === 'provider') {
                await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ success: false, data: null, error: { code: 'FX_PROVIDER_UNAVAILABLE', message: 'FX provider is not configured. Provider selection remains pending.' } }) });
                return;
            }
            status = 201;
            data = { ...rate, status: 'pending_approval' };
        }
        else
            data = [rate, { ...rate, id: 'pending', from_currency_code: 'BWP', to_currency_code: 'ZAR', rate: '1.42000000', status: 'pending_approval' }];
        await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ data }) });
    });
    return calls;
}
async function screenshot(page: Page, name: string) { fs.mkdirSync(out, { recursive: true }); await page.evaluate(() => document.querySelectorAll('*').forEach(el => { if (el.scrollTop > 0)
    el.scrollTop = 0; })); await page.screenshot({ path: path.join(out, name), fullPage: true }); }
test('currency maker and checker controls desktop and mobile', async ({ page, context }) => { await session(context); const calls = await fixture(page); await page.goto('/multi-currency/currencies'); await expect(page.getByText('US Dollar')).toBeVisible(); await page.getByRole('button', { name: 'Request disable USD' }).click(); await expect(page.getByText('Pending: Disable').last()).toBeVisible(); expect(calls[0]?.key).toMatch(/^[0-9a-f-]{36}$/); await screenshot(page, 'currencies-desktop.png'); await page.getByRole('button', { name: 'Approve USD' }).click(); await expect(page.getByRole('button', { name: 'Request enable USD' })).toBeVisible(); await page.setViewportSize({ width: 390, height: 844 }); await screenshot(page, 'currencies-mobile.png'); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true); });
test('rate history chart and provider failure are explicit', async ({ page, context }) => { await session(context); const calls = await fixture(page); await page.goto('/multi-currency/fx-rates'); await expect(page.getByText('Manual (locked)').first()).toBeVisible(); await page.getByRole('button', { name: 'BWP/USD', exact: true }).click(); await expect(page.getByRole('img', { name: /Exchange rate changes/ })).toBeVisible(); await screenshot(page, 'fx-desktop.png'); await page.getByRole('heading', { name: 'BWP/USD rate history' }).evaluate(el => el.scrollIntoView({ block: 'start' })); await page.screenshot({ path: path.join(out, 'history-desktop.png') }); await page.getByLabel('From currency', { exact: true }).fill('BWP'); await page.getByLabel('To currency', { exact: true }).fill('USD'); await page.getByLabel('Spread %', { exact: true }).fill('1.25'); await page.getByLabel('Rate mode').selectOption('provider'); await page.getByRole('button', { name: 'Submit for approval' }).click(); await expect(page.getByRole('status')).toContainText('FX provider is not configured'); expect((calls[0]?.body as {
    rate?: string;
}).rate).toBeUndefined(); await page.setViewportSize({ width: 390, height: 844 }); await screenshot(page, 'fx-mobile.png'); await page.getByRole('heading', { name: 'BWP/USD rate history' }).evaluate(el => el.scrollIntoView({ block: 'start' })); await page.screenshot({ path: path.join(out, 'history-mobile.png') }); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true); await page.keyboard.press('Tab'); expect(await page.evaluate(() => document.activeElement?.tagName)).not.toBe('BODY'); });
test('unauthorized route is gated', async ({ page, context }) => { await session(context, []); await fixture(page); await page.goto('/multi-currency/fx-rates'); await expect(page).toHaveURL(/\/403/); });
