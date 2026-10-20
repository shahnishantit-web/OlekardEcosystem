const fs = require('fs');
const path = require('path');
const root = __dirname;
const report = JSON.parse(fs.readFileSync(path.join(root, 'link-audit-latest.json'), 'utf8'));
const contracts = JSON.parse(fs.readFileSync(path.join(root, 'link-audit-api-contracts.json'), 'utf8'));
const details = JSON.parse(fs.readFileSync(path.join(root, 'link-audit-extra-links.json'), 'utf8'));
const expected = new Set(['/401', '/429', '/503', '/504']);
const notFound = report.pages.filter(page => page.status === 404);
const errors = report.pages.filter(page => page.error || page.status >= 400 && !expected.has(page.href.split('?')[0]));
const api404 = [...new Map(report.apiErrors.filter(error => error.status === 404).map(error => [error.path, error])).values()];
const statuses = report.pages.reduce((counts, page) => {
  counts[page.status || 'error'] = (counts[page.status || 'error'] || 0) + 1;
  return counts;
}, {});
const unavailable = details.checks.filter(check => check.status !== 200);
const lines = [
  '# Admin panel link audit', '',
  `Completed: ${report.finishedAt || 'in progress'}`, '',
  `Origin: ${report.origin}. Authenticated with the local administrator.`, '',
  `- Checked **${report.pages.length} unique page paths**, including rendered sublinks and verified existing-record detail URLs.`,
  `- Remaining page HTTP 404 responses: **${notFound.length}**.`,
  `- Observed page API HTTP 404 responses: **${api404.length}**.`,
  `- Other unexpected page HTTP/navigation errors: **${errors.length}**.`,
  `- Statically resolved admin GET references: **${contracts.checks.length}**, unmatched registered routes: **${contracts.unmatched.length}**.`,
  '- Requests were read-only apart from authentication. No financial or administrative forms were submitted.', '',
  '## Repairs', '',
  '- Added missing Payment Providers list/create/detail/edit and Payment Exceptions page routes, using the existing screens.',
  '- Corrected payment API paths to the registered `/api/v1/admin/payment/...` prefix.',
  '- Corrected the reconciliation Back link and added `/reconciliation` compatibility redirection.',
  '- Repaired New Report navigation to focus the existing request form, and redirected `/reports/catalogue` to it.',
  '- Corrected three Community Campaign route imports which previously caused HTTP 500 compilation failures.',
  '- Corrected stale withdrawal controller namespaces/action references discovered while exporting the backend route inventory.', '',
  'Four originally probed missing page URLs returned HTTP 404: `/payment/providers`, `/payment/providers/create`, `/payment/reconciliation/exceptions`, and `/reconciliation`.', '',
  '## Verification', '',
  '- Payment UI: 36 tests passed.',
  '- Reports UI, including New Report focus: 32 tests passed.',
  '- Reconciliation UI, including Back navigation: 39 tests passed.',
  '- Backend route inventory exports successfully; all 95 resolved GET references match registered route patterns.', '',
  '## Scope and remaining observations', '',
  'Repeated navigation links are checked once per pathname; query filters and fragments share the same page route. External links and business-action buttons are excluded. Detail URLs use existing records; no dummy IDs or seed records were created.', '',
  'The dedicated `/401`, `/429`, `/503`, and `/504` demonstration pages intentionally return those HTTP statuses.', '',
  'Non-404 API responses observed separately: payment provider/exception reads return HTTP 403 for this account, and a dashboard transaction query using `per_page=500` returns HTTP 422. Authorization was not relaxed.', '',
  'Detail patterns without a successfully visited existing-record URL (empty data or restricted access):', '',
  ...report.unvisitedDynamicTemplates.map(template => '- `' + template + '`'), '',
  'Supplemental record-discovery API limitations:', '',
  ...unavailable.map(check => '- `' + check.uri + '`: ' + (check.status || check.error) + (check.code ? ' (' + check.code + ')' : '')), '',
  '## Checked URLs', '',
  '| Page | HTTP | Destination |', '| --- | ---: | --- |',
  ...report.pages.slice().sort((a, b) => a.href.localeCompare(b.href)).map(page =>
    `| [${page.href}](${report.origin}${page.href}) | ${page.status || 'error'} | ${page.destination || page.error || ''} |`), '',
];
fs.writeFileSync(path.join(root, 'link-audit-report.md'), lines.join('\n'));
console.log(JSON.stringify({ pages: report.pages.length, statuses, page404: notFound.length,
  api404: api404.length, otherErrors: errors.length, unvisitedTemplates: report.unvisitedDynamicTemplates.length }, null, 2));
