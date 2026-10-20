const fs = require('fs');
const base = __dirname;
const result = JSON.parse(fs.readFileSync(base + '/combined-results.json', 'utf8'));
const cases = [];
function walk(suite) {
  for (const spec of suite.specs || []) for (const test of spec.tests || []) {
    const last = test.results.at(-1);
    cases.push({title: spec.title, file: spec.file, status: last?.status, error: last?.error?.message});
  }
  for (const child of suite.suites || []) walk(child);
}
walk(result);
const groups = {};
for (const name of ['fixture', 'live']) {
  const rows = cases.filter(c => name === 'fixture' ? c.file.includes('admin-fixture-') : c.file.includes('admin-live-audit'));
  groups[name] = {total: rows.length, passed: rows.filter(c => c.status === 'passed').length, failed: rows.filter(c => c.status !== 'passed').length};
}
const summary = {groups, failures: cases.filter(c => c.status !== 'passed')};
fs.writeFileSync(base + '/validation-summary.json', JSON.stringify(summary, null, 2));
console.log(JSON.stringify(groups));
for (const c of summary.failures.filter(c => c.file.includes('fixture'))) console.log(c.title, c.error);
