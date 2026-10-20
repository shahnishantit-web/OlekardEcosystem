const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '../../wallet-ecosystem/apps/admin');
const { chromium } = require(path.join(root, 'node_modules/@playwright/test'));
const ts = require(path.join(root, 'node_modules/typescript'));
const origin = process.env.ADMIN_AUDIT_ORIGIN || 'http://localhost:3000';
const out = path.join(__dirname, 'link-audit-latest.json');
const walk = dir => fs.readdirSync(dir, { withFileTypes: true }).flatMap(e =>
  e.isDirectory() ? walk(path.join(dir, e.name)) : [path.join(dir, e.name)]);
const routes = walk(path.join(root, 'app')).filter(f => /[/\\]page\.[jt]sx?$/.test(f)).map(f =>
  '/' + path.relative(path.join(root, 'app'), path.dirname(f)).split(path.sep)
    .filter(s => !s.startsWith('(') && !s.startsWith('@')).join('/'));
const templates = routes.filter(r => r.includes('['));
const results = { origin, startedAt: new Date().toISOString(), routes, pages: [],
  links: [], apiErrors: [], assetErrors: [], skipped: [], blockedMutations: [] };
if (process.argv.includes('--resume') && fs.existsSync(out)) Object.assign(results, JSON.parse(fs.readFileSync(out, 'utf8')));
if (process.argv.includes('--retry-errors')) {
  results.pages = results.pages.filter(p => p.status < 400 && !p.error && !p.denied);
}
delete results.fatal;
results.routes = routes;
results.links = [...new Map(results.links.map(link => [link.href + '|' + link.source, link])).values()];
const linkEdges = new Set(results.links.map(link => link.href + '|' + link.source));
const completed = new Set(results.pages.map(p => p.href.split('?')[0]));
const queue = [];
const scheduled = new Set();
const isMutationLink = pathname => /\/(logout|signout|sign-out)(\/|$)/i.test(pathname);
function enqueue(href, source) {
  if (!href || /^(#|mailto:|tel:|javascript:)/i.test(href)) return;
  let url;
  try { url = new URL(href, origin); } catch { return; }
  if (url.origin !== origin) { results.skipped.push({ href: url.href, source, reason: 'external' }); return; }
  if (url.pathname.includes('[') || url.pathname.includes('{') || isMutationLink(url.pathname)) return;
  url.hash = '';
  const hrefPath = url.pathname + url.search;
  const edge = hrefPath + '|' + source;
  if (!linkEdges.has(edge)) {
    linkEdges.add(edge);
    results.links.push({ href: hrefPath, source });
  }
  // Tabs and filters share the same route; preserve a concrete query for the first visit.
  if (scheduled.has(url.pathname)) return;
  scheduled.add(url.pathname);
  if (completed.has(url.pathname)) return;
  queue.push({ href: url.pathname + url.search, source });
}
for (const route of routes.filter(r => !r.includes('['))) enqueue(route, 'page inventory');
for (const link of [...results.links]) enqueue(link.href, link.source);
if (fs.existsSync(path.join(__dirname, 'link-audit-extra-links.json'))) {
  for (const link of JSON.parse(fs.readFileSync(path.join(__dirname, 'link-audit-extra-links.json'), 'utf8')).links) enqueue(link.href, link.source);
}
for (const file of ['app', 'src', 'components', 'lib'].flatMap(d => walk(path.join(root, d)))
    .filter(f => /\.[jt]sx?$/.test(f))) {
  const source = ts.createSourceFile(file, fs.readFileSync(file, 'utf8'), ts.ScriptTarget.Latest, true);
  const add = node => {
    if (node && ts.isStringLiteralLike(node) && node.text.startsWith('/')) {
      enqueue(node.text, path.relative(root, file).split(path.sep).join('/'));
    }
  };
  function visit(node) {
    if (ts.isJsxAttribute(node) && node.name.getText(source) === 'href') {
      add(ts.isJsxExpression(node.initializer || {}) ? node.initializer.expression : node.initializer);
    }
    if (ts.isPropertyAssignment(node) && ['href', 'path'].includes(node.name.getText(source).replace(/['"]/g, ''))) add(node.initializer);
    if (ts.isCallExpression(node) && ts.isPropertyAccessExpression(node.expression)
        && ['push', 'replace'].includes(node.expression.name.text)
        && /router|navigation/i.test(node.expression.expression.getText(source))) add(node.arguments[0]);
    ts.forEachChild(node, visit);
  }
  visit(source);
}
function save() {
  const temporary = out + '.pending';
  fs.writeFileSync(temporary, JSON.stringify(results, null, 2));
  for (let attempt = 0; attempt < 10; attempt++) {
    try { fs.renameSync(temporary, out); return; }
    catch (error) {
      if (!['EBUSY', 'EPERM', 'EACCES', 'UNKNOWN'].includes(error.code) || attempt === 9) throw error;
      Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, 100);
    }
  }
}

(async () => {
  const browser = await chromium.launch({ headless: true });
  try {
    const context = await browser.newContext();
    const login = await context.newPage();
    await login.goto(origin + '/login');
    await login.locator('#email-input').fill('admin@olekard.local');
    await login.locator('#password-input').fill('Admin123!');
    await login.getByRole('button', { name: 'Sign in', exact: true }).click();
    await login.waitForURL(url => !url.pathname.startsWith('/login'), { timeout: 45000 });
    for (const href of await login.locator('a[href]').evaluateAll(nodes => nodes.map(a => a.getAttribute('href')))) enqueue(href, '/dashboard');
    await login.close();
    // Navigation audit is read-only: never submit a page's business operation.
    await context.route('**/*', route => {
      const request = route.request();
      if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method())) {
        results.blockedMutations.push({ method: request.method(), url: new URL(request.url()).pathname });
        return route.abort();
      }
      return route.continue();
    });
    let cursor = 0;
    async function worker() {
      const page = await context.newPage();
      let active;
      const responseTasks = new Set();
      const requestSources = new WeakMap();
      page.on('request', request => requestSources.set(request, active?.href));
      page.on('response', response => {
        if (response.request().method() !== 'GET') return;
        const source = requestSources.get(response.request()) || active?.href;
        const task = (async () => {
          const url = new URL(response.url());
          if (/^\/(api\/)?v1\//.test(url.pathname)) {
            const payload = await response.json().catch(() => ({}));
            if (response.status() >= 400) results.apiErrors.push({ source, path: url.pathname + url.search,
              status: response.status(), code: payload.error?.code, message: payload.error?.message });
            if (response.ok() && source) {
              const base = source.split('?')[0];
              const resource = base.split('/').pop();
              const apiResource = url.pathname.split('/').pop();
              const children = templates.filter(t => t.startsWith(base + '/['));
              const data = payload.data;
              const rows = Array.isArray(data) ? data : data?.items || data?.data || data?.results || [];
              if (resource === apiResource && children.length && Array.isArray(rows)) {
                for (const row of rows) {
                  if (typeof row.id !== 'string') continue;
                  for (const template of children) enqueue(template.replace(/\[[^\]]+\]/, encodeURIComponent(row.id)), source);
                }
              }
            }
          } else if (response.status() >= 400 && response.request().resourceType() !== 'document') {
            results.assetErrors.push({ source, path: url.pathname, status: response.status() });
          }
        })().finally(() => responseTasks.delete(task));
        responseTasks.add(task);
      });
      while (cursor < queue.length) {
        active = queue[cursor++];
        const entry = { ...active };
        try {
          const response = await page.goto(origin + active.href, { waitUntil: 'domcontentloaded', timeout: 60000 });
          entry.status = response?.status();
          entry.destination = new URL(page.url()).pathname;
          await page.waitForLoadState('networkidle', { timeout: 3000 }).catch(() => {});
          await page.waitForTimeout(350);
          entry.destination = new URL(page.url()).pathname;
          const body = await page.locator('body').innerText();
          entry.visibleNotFound = /404[\s\S]{0,80}(page|not found)|this page could not be found/i.test(body);
          entry.denied = (entry.destination === '/403' || entry.destination === '/login') && entry.destination !== active.href.split('?')[0];
          for (const href of await page.locator('a[href]').evaluateAll(nodes => nodes.map(a => a.getAttribute('href')))) enqueue(href, active.href);
        } catch (error) { entry.error = error.message.split('\n')[0]; }
        if (!entry.status || entry.error) {
          try {
            const probe = await context.request.get(origin + entry.href, { timeout: 60000 });
            entry.navigationWarning = entry.error || 'Navigation did not return a document response.';
            entry.status = probe.status();
            entry.destination = new URL(probe.url()).pathname;
            delete entry.error;
          } catch (error) { entry.error = error.message.split('\n')[0]; }
        }
        await Promise.allSettled([...responseTasks]);
        results.pages.push(entry);
        save();
        console.log(JSON.stringify({ checked: results.pages.length, discovered: queue.length, path: entry.href,
          status: entry.status, destination: entry.destination, error: entry.error }));
      }
      await page.close();
    }
    // Keep development compilation and the local API responsive.
    await Promise.all([worker(), worker()]);
    results.finishedAt = new Date().toISOString();
    results.unvisitedDynamicTemplates = templates.filter(template => {
      const pattern = new RegExp('^' + template.replace(/\[\.\.\.[^\]]+\]/g, '.+').replace(/\[[^\]]+\]/g, '[^/]+') + '/?$');
      return !results.pages.some(p => !routes.includes(p.href.split('?')[0]) && pattern.test(p.href.split('?')[0]) && p.status === 200);
    });
    results.summary = { checked: results.pages.length, discoveredLinks: results.links.length,
      http404: results.pages.filter(p => p.status === 404),
      visibleNotFound: results.pages.filter(p => p.visibleNotFound),
      otherErrors: results.pages.filter(p => p.status >= 400 && p.status !== 404 || p.error),
      denied: results.pages.filter(p => p.denied),
      api404: results.apiErrors.filter(e => e.status === 404),
      unvisitedDynamicTemplates: results.unvisitedDynamicTemplates };
    save();
    console.log(JSON.stringify(results.summary, null, 2));
  } finally { await browser.close(); }
})().catch(error => { results.fatal = error.message; save(); console.error(error.message); process.exitCode = 1; });
