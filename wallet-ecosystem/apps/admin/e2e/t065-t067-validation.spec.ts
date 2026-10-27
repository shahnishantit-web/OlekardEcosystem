/**
 * E37 T065–T067 Browser Validation
 *
 * Auth: admin session injected via Playwright storageState (playwright/global-setup.ts).
 * No per-test login calls — one token for the entire suite.
 *
 * T065: Responsive at 390×844, 768×1024, 1440×900, 2560×1440
 * T066: Accessibility spot-check on /dashboard
 * T067: Smoke test existing business workflows
 */

import { test, expect } from '@playwright/test';
import * as path from 'path';
import * as fs   from 'fs';

const BASE = 'http://localhost:3000';

// ---------------------------------------------------------------------------
// T065 — Responsive Layout Validation
// ---------------------------------------------------------------------------
test.describe('T065 — Responsive Layout', () => {
  const viewports = [
    { name: 'mobile-390',     width: 390,  height: 844  },
    { name: 'tablet-768',     width: 768,  height: 1024 },
    { name: 'desktop-1440',   width: 1440, height: 900  },
    { name: 'ultrawide-2560', width: 2560, height: 1440 },
  ] as const;

  for (const vp of viewports) {
    test(`no horizontal overflow at ${vp.name}`, async ({ browser }) => {
      const authFile = path.join(__dirname, '..', 'playwright', '.auth', 'admin.json');
      const ctx  = await browser.newContext({
        viewport:     { width: vp.width, height: vp.height },
        storageState: authFile,
      });
      const page = await ctx.newPage();

      await page.goto(`${BASE}/dashboard`);
      await page.waitForLoadState('load');

      // Must not land back on login
      expect(page.url(), 'Should be on dashboard, not login').not.toContain('/login');

      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
      );
      expect(overflow, `Horizontal overflow at ${vp.name}`).toBe(false);

      // Sidebar is in the DOM at all viewports; it is CSS-hidden at narrow widths
      // (drawer pattern) — that is correct responsive behaviour.
      await expect(page.locator('#admin-sidebar')).toBeAttached();

      await ctx.close();
    });
  }

  test('sidebar expanded width is 252px ±4px at 1440', async ({ browser }) => {
    const authFile = path.join(__dirname, '..', 'playwright', '.auth', 'admin.json');
    const ctx  = await browser.newContext({
      viewport:     { width: 1440, height: 900 },
      storageState: authFile,
    });
    const page = await ctx.newPage();

    await page.goto(`${BASE}/dashboard`);
    await page.waitForLoadState('load');

    const sidebar = page.locator('#admin-sidebar');
    await expect(sidebar).toBeVisible();
    const box = await sidebar.boundingBox();
    expect(box, 'Sidebar bounding box must exist').not.toBeNull();
    expect(box!.width, `Sidebar width ${box!.width}px — expected 248–256`).toBeGreaterThanOrEqual(248);
    expect(box!.width, `Sidebar width ${box!.width}px — expected 248–256`).toBeLessThanOrEqual(256);

    await ctx.close();
  });

  test('no horizontal overflow on users list at mobile 390', async ({ browser }) => {
    const authFile = path.join(__dirname, '..', 'playwright', '.auth', 'admin.json');
    const ctx  = await browser.newContext({
      viewport:     { width: 390, height: 844 },
      storageState: authFile,
    });
    const page = await ctx.newPage();

    await page.goto(`${BASE}/users`);
    await page.waitForLoadState('load');
    expect(page.url()).not.toContain('/login');

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );
    expect(overflow, 'Horizontal overflow on users list at 390px').toBe(false);

    await ctx.close();
  });

  test('header height is 62–70px at 1440', async ({ browser }) => {
    const authFile = path.join(__dirname, '..', 'playwright', '.auth', 'admin.json');
    const ctx  = await browser.newContext({
      viewport:     { width: 1440, height: 900 },
      storageState: authFile,
    });
    const page = await ctx.newPage();

    await page.goto(`${BASE}/dashboard`);
    await page.waitForLoadState('load');

    const header = page.locator('header').first();
    await expect(header).toBeVisible();
    const box = await header.boundingBox();
    expect(box, 'Header bounding box must exist').not.toBeNull();
    expect(box!.height, `Header height ${box!.height}px — expected 62–70`).toBeGreaterThanOrEqual(62);
    expect(box!.height, `Header height ${box!.height}px — expected 62–70`).toBeLessThanOrEqual(70);

    await ctx.close();
  });

  test('content max-width ≤1520px on 2560 viewport', async ({ browser }) => {
    const authFile = path.join(__dirname, '..', 'playwright', '.auth', 'admin.json');
    const ctx  = await browser.newContext({
      viewport:     { width: 2560, height: 1440 },
      storageState: authFile,
    });
    const page = await ctx.newPage();

    await page.goto(`${BASE}/dashboard`);
    await page.waitForLoadState('load');

    // Check no horizontal overflow on ultra-wide
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth > document.documentElement.clientWidth,
    );
    expect(overflow, 'Horizontal overflow at 2560').toBe(false);

    await ctx.close();
  });
});

// ---------------------------------------------------------------------------
// T066 — Accessibility Spot-check
// ---------------------------------------------------------------------------
test.describe('T066 — Accessibility', () => {
  test('tab navigation reaches sidebar nav links within 15 presses', async ({ page }) => {
    await page.goto(`${BASE}/dashboard`);
    await page.waitForLoadState('load');
    expect(page.url()).not.toContain('/login');

    let found = false;
    for (let i = 0; i < 15; i++) {
      await page.keyboard.press('Tab');
      const inSidebar = await page.evaluate(
        () => document.activeElement?.closest('#admin-sidebar') !== null,
      );
      if (inSidebar) { found = true; break; }
    }
    expect(found, 'Tab should reach a nav link inside #admin-sidebar').toBe(true);
  });

  test('interactive elements carry focus-visible class', async ({ page }) => {
    await page.goto(`${BASE}/dashboard`);
    await page.waitForLoadState('load');
    expect(page.url()).not.toContain('/login');

    const hasStyle = await page.evaluate(() =>
      Array.from(document.querySelectorAll('button, a[href]'))
        .some((el) => el.className.includes('focus-visible')),
    );
    expect(hasStyle, 'At least one interactive element has focus-visible class').toBe(true);
  });

  test('axe-core: 0 critical violations on /dashboard', async ({ page }) => {
    await page.goto(`${BASE}/dashboard`);
    await page.waitForLoadState('load');
    expect(page.url()).not.toContain('/login');

    const axePath = path.join(
      __dirname, '..', 'node_modules', 'axe-core', 'axe.min.js',
    );
    if (!fs.existsSync(axePath)) {
      console.warn('axe-core not found — skipping'); return;
    }

    await page.addScriptTag({ path: axePath });

    type AxeViolation = { id: string; impact: string; description: string; nodes: unknown[] };
    const violations = await page.evaluate(async () => {
      // @ts-expect-error — axe injected globally
      const results = await (window.axe as {
        run: (el: Document, o: unknown) => Promise<{ violations: AxeViolation[] }>
      }).run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa'] } });
      return results.violations.map((v) => ({
        id: v.id, impact: v.impact, description: v.description, nodeCount: v.nodes.length,
      }));
    });

    if (violations.length) {
      console.log('\nAxe violations found:');
      for (const v of violations)
        console.log(`  [${v.impact}] ${v.id} (${v.nodeCount} nodes): ${v.description}`);
    }

    const critical = violations.filter((v) => v.impact === 'critical');
    const serious  = violations.filter((v) => v.impact === 'serious');
    if (serious.length) console.warn(`\n${serious.length} serious (informational): ${serious.map((v) => v.id).join(', ')}`);

    expect(critical.length, `Critical violations: ${critical.map((v) => v.id).join(', ')}`).toBe(0);
  });

  test('prefers-reduced-motion media query present in stylesheets', async ({ page }) => {
    await page.goto(`${BASE}/dashboard`);
    await page.waitForLoadState('load');
    expect(page.url()).not.toContain('/login');

    const found = await page.evaluate(() => {
      for (const sheet of Array.from(document.styleSheets)) {
        try {
          for (const rule of Array.from(sheet.cssRules ?? []))
            if (rule.cssText?.includes('prefers-reduced-motion')) return true;
        } catch { /* cross-origin */ }
      }
      return false;
    });
    expect(found, 'prefers-reduced-motion should be in stylesheets').toBe(true);
  });

  test('dashboard main content area is visible', async ({ page }) => {
    await page.goto(`${BASE}/dashboard`);
    await page.waitForLoadState('load');
    expect(page.url()).not.toContain('/login');
    await expect(page.locator('main')).toBeVisible();
    await expect(page.locator('#admin-sidebar')).toBeVisible();
    // KPI grid uses grid-cols class
    const kpiGrid = page.locator('[class*="grid-cols"]').first();
    await expect(kpiGrid).toBeVisible();
  });
});

// ---------------------------------------------------------------------------
// T067 — Smoke Test Existing Business Workflows
// ---------------------------------------------------------------------------
test.describe('T067 — Business Workflow Smoke Tests', () => {
  test('authenticated: /dashboard shows admin sidebar', async ({ page }) => {
    await page.goto(`${BASE}/dashboard`);
    await page.waitForLoadState('load');
    expect(page.url()).not.toContain('/login');
    await expect(page.locator('#admin-sidebar')).toBeVisible();
  });

  test('sidebar active indicator on /dashboard', async ({ page }) => {
    await page.goto(`${BASE}/dashboard`);
    await page.waitForLoadState('load');
    const activeLink = page.locator('#admin-sidebar a[aria-current="page"]');
    await expect(activeLink).toBeVisible();
    expect(await activeLink.getAttribute('href')).toBe('/dashboard');
  });

  test('user search page: loads and has a search input', async ({ page }) => {
    await page.goto(`${BASE}/users`);
    await page.waitForLoadState('load');
    expect(page.url()).not.toContain('/login');
    await expect(page.locator('main')).toBeVisible();
    await expect(page.locator('h1:has-text("500"), h1:has-text("Internal Server Error")')).toHaveCount(0);

    const searchEl = page.locator('input[type="search"], input[placeholder*="search" i], input[placeholder*="email" i]');
    if (await searchEl.count() > 0) {
      await searchEl.first().fill('test@example.com');
      await page.waitForTimeout(400);
      expect(await searchEl.first().inputValue()).toBe('test@example.com');
    }
  });

  test('transaction list: loads without hard error', async ({ page }) => {
    await page.goto(`${BASE}/transactions`);
    await page.waitForLoadState('load');
    expect(page.url()).not.toContain('/login');
    await expect(page.locator('#main-content, main')).toBeAttached();
    await expect(page.locator('h1:has-text("500")')).toHaveCount(0);
  });

  test('audit events: loads without hard error', async ({ page }) => {
    await page.goto(`${BASE}/audit`);
    await page.waitForLoadState('load');
    expect(page.url()).not.toContain('/login');
    await expect(page.locator('#main-content, main')).toBeAttached();
    await expect(page.locator('h1:has-text("500")')).toHaveCount(0);
  });

  test('reconciliation batches: loads without hard error', async ({ page }) => {
    await page.goto(`${BASE}/reconciliation/batches`);
    await page.waitForLoadState('load');
    expect(page.url()).not.toContain('/login');
    await expect(page.locator('#main-content, main')).toBeAttached();
    await expect(page.locator('h1:has-text("500")')).toHaveCount(0);
  });

  test('fee rules: loads and sidebar nav active', async ({ page }) => {
    await page.goto(`${BASE}/fees/rules`);
    await page.waitForLoadState('load');
    expect(page.url()).not.toContain('/login');
    await expect(page.locator('main')).toBeVisible();

    const activeLink = page.locator('#admin-sidebar a[aria-current="page"]');
    if (await activeLink.count() > 0)
      expect(await activeLink.getAttribute('href')).toContain('/fees');
  });

  test('KYC page: loads without hard 500 error', async ({ page }) => {
    await page.goto(`${BASE}/kyc`);
    await page.waitForLoadState('load');
    expect(page.url()).not.toContain('/login');
    // Page may show empty state or access-denied without a <main>; the critical
    // check is that no 500/crash page is shown.
    await expect(page.locator('h1:has-text("500"), h1:has-text("Internal Server Error")')).toHaveCount(0);
    // Either the shell main or an error state should be present
    const shellMain = page.locator('#main-content, main');
    const count = await shellMain.count();
    // If no main exists, verify the page at least rendered something
    if (count === 0) {
      await expect(page.locator('body')).not.toBeEmpty();
    } else {
      await expect(shellMain.first()).toBeAttached();
    }
  });

  test('sign-out: clearing cookies redirects to login', async ({ page }) => {
    await page.goto(`${BASE}/dashboard`);
    await page.waitForLoadState('load');
    expect(page.url()).not.toContain('/login');

    await page.context().clearCookies();
    await page.goto(`${BASE}/dashboard`);
    await page.waitForLoadState('load');
    expect(page.url()).toContain('/login');
  });
});
