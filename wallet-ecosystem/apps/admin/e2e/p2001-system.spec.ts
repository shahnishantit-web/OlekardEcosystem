import { test, expect, type BrowserContext, type Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
const out = path.resolve(__dirname, '../../../../specs/1001-http-system-status/screenshots');
async function session(context: BrowserContext) { const token = 'e30.' + Buffer.from(JSON.stringify({ sub: 'tester', permissions: ['security.events.read'] })).toString('base64url') + '.test'; await context.addCookies([{ name: 'admin_token', value: token, url: 'http://localhost:3024' }, { name: 'admin_sid', value: 'status-session', url: 'http://localhost:3024' }, { name: 'admin_user', value: encodeURIComponent(JSON.stringify({ id: 'tester', name: 'Status administrator', email: 'status@example.test' })), url: 'http://localhost:3024' }]); }
async function shot(page: Page, name: string) { fs.mkdirSync(out, { recursive: true }); await page.screenshot({ path: path.join(out, name), fullPage: true }); }
test('standalone HTTP routes preserve codes and recovery actions', async ({ page }) => { for (const code of [401, 429, 503, 504]) {
    const r = await page.goto('/' + code);
    expect(r?.status()).toBe(code);
    await expect(page.locator('h1')).toBeVisible();
    await expect(page.getByRole('link', { name: code === 401 ? 'Sign in' : 'Return to sign in', exact: true })).toBeVisible();
} await shot(page, 'timeout-root-desktop.png'); await page.setViewportSize({ width: 390, height: 844 }); await shot(page, 'timeout-root-mobile.png'); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true); });
test('authenticated proxy errors retain shell without replay', async ({ page, context }) => {
    await session(context);
    for (const code of [429, 503, 504]) {
        let count = 0;
        await page.route('**/v1/admin/security/events?*', async (route) => { count++; await route.fulfill({ status: code, contentType: 'text/html', headers: { 'Retry-After': '60', 'X-Correlation-ID': 'support-ref-123' }, body: '<h1>PRIVATE PROXY TRACE</h1>' }); });
        await page.goto('/security/events');
        await expect(page.getByText('Error ' + code, { exact: true })).toBeVisible();
        await expect(page.getByRole('navigation').first()).toBeVisible();
        expect(await page.textContent('body')).not.toContain('PRIVATE PROXY TRACE');
        expect(count).toBeLessThanOrEqual(2); // Next development StrictMode mounts the existing GET effect twice.
        if (code === 503) {
            await shot(page, 'unavailable-shell-desktop.png');
            await page.setViewportSize({ width: 390, height: 844 });
            await shot(page, 'unavailable-shell-mobile.png');
            await page.setViewportSize({ width: 1440, height: 1000 });
        }
        await page.unroute('**/v1/admin/security/events?*');
    }
});
test('offline state and connection recovery preserve shell', async ({ page, context }) => { await session(context); await page.route('**/v1/**', route => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ data: { items: [], next_cursor: null } }) })); await page.goto('/security/events'); await expect(page.getByRole('heading', { name: 'Security Events' })).toBeVisible(); await context.setOffline(true); await expect(page.getByRole('heading', { name: 'You are offline' })).toBeVisible(); await context.setOffline(false); await expect(page.getByRole('heading', { name: 'Security Events' })).toBeVisible(); });
test('legacy browser has a script-free no-JS fallback', async ({ browser }) => { const context = await browser.newContext({ javaScriptEnabled: false, userAgent: 'Mozilla/5.0 (Windows NT 6.1; Trident/7.0; rv:11.0) like Gecko' }); const page = await context.newPage(); const errors: string[] = []; page.on('pageerror', e => errors.push(e.message)); const response = await page.goto('/dashboard'); expect(response?.status()).toBe(200); expect(await response?.text()).not.toMatch(/<script/i); await expect(page.getByRole('heading', { name: 'Unsupported browser' })).toBeVisible(); await expect(page.getByRole('link')).toHaveCount(4); await shot(page, 'unsupported-desktop.png'); await page.setViewportSize({ width: 390, height: 844 }); await shot(page, 'unsupported-mobile.png'); expect(errors).toEqual([]); await context.close(); });

test('repaired role dialog keeps layout, keyboard dismissal and action payload',async({page,context})=>{
 await session(context);const mutations:{body:unknown;key:string|undefined}[]=[];
 await page.route('**/v1/**',async route=>{const request=route.request();if(request.method()==='POST'){mutations.push({body:request.postDataJSON(),key:request.headers()['idempotency-key']});await route.fulfill({contentType:'application/json',body:JSON.stringify({data:{role_id:'new-role',role_name:'repair_test',permission_slugs:[]}})});}else await route.fulfill({contentType:'application/json',body:JSON.stringify({data:{data:[],meta:{total:0,current_page:1,per_page:50,last_page:1}}})});});
 await page.goto('/roles');const open=page.getByRole('button',{name:'Create new role'});await open.click();
 const dialog=page.getByRole('dialog',{name:'Create Custom Role'});await expect(dialog).toBeFocused();await page.keyboard.press('Tab');await expect(page.getByLabel('Role Name')).toBeFocused();
 await page.getByLabel('Role Name').fill('repair_test');await expect(dialog).toBeVisible();await shot(page,'repair-role-dialog-desktop.png');
 await page.keyboard.press('Escape');await expect(dialog).toHaveCount(0);await expect(open).toBeFocused();
 await open.click();await page.getByLabel('Role Name').fill('repair_test');await page.setViewportSize({width:390,height:844});await shot(page,'repair-role-dialog-mobile.png');expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
 await page.getByRole('button',{name:'Confirm create role'}).click();await expect(dialog).toHaveCount(0);expect(mutations).toHaveLength(1);expect(mutations[0]?.body).toEqual({role_name:'repair_test',permission_slugs:[]});expect(mutations[0]?.key).toMatch(/^create-role-repair_test-[a-z0-9]+-[a-z0-9]+$/);
});
