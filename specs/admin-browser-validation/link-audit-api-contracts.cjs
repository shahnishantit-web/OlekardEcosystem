const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '../../wallet-ecosystem/apps/admin');
const ts = require(path.join(root, 'node_modules/typescript'));
const raw = fs.readFileSync(path.join(__dirname, 'link-audit-api-routes.json'));
const registered = JSON.parse((raw[0] === 255 && raw[1] === 254 ? raw.toString('utf16le') : raw.toString('utf8')).replace(/^\uFEFF/, ''));
const walk = dir => fs.readdirSync(dir, { withFileTypes: true }).flatMap(e => e.isDirectory()
  ? walk(path.join(dir, e.name)) : [path.join(dir, e.name)]);
const checks = [];
for (const file of ['app', 'src', 'lib', 'components'].flatMap(d => walk(path.join(root, d)))
    .filter(f => /\.[jt]sx?$/.test(f) && !/\.(test|spec)\./.test(f))) {
  const source = ts.createSourceFile(file, fs.readFileSync(file, 'utf8'), ts.ScriptTarget.Latest, true);
  function value(node) {
    if (!node) return '';
    if (ts.isStringLiteralLike(node)) return node.text;
    if (ts.isTemplateExpression(node)) return node.head.text + node.templateSpans.map(s => '{value}' + s.literal.text).join('');
    if (ts.isBinaryExpression(node) && node.operatorToken.kind === ts.SyntaxKind.PlusToken) return value(node.left) + value(node.right);
    return node.getText(source) === 'AdminAppClient.BASE' ? '/v1/admin-app' : '{value}';
  }
  function visit(node) {
    if (ts.isCallExpression(node) && ts.isPropertyAccessExpression(node.expression)
        && ['get', 'getBlob'].includes(node.expression.name.text)) {
      const uri = value(node.arguments[0]).split('?')[0].replace(/([^/])\{value\}$/, '$1');
      if (/^\/(api\/)?v1\//.test(uri)) {
        const matches = registered.filter(route => route.method.split('|').includes('GET')
          && new RegExp('^/' + route.uri.replace(/\{[^}]+\}/g, '[^/]+') + '/?$').test(uri));
        checks.push({ source: path.relative(root, file).split(path.sep).join('/'), uri,
          registered: matches.length > 0 });
      }
    }
    ts.forEachChild(node, visit);
  }
  visit(source);
}
const report = { checkedAt: new Date().toISOString(), checks, unmatched: checks.filter(c => !c.registered) };
fs.writeFileSync(path.join(__dirname, 'link-audit-api-contracts.json'), JSON.stringify(report, null, 2));
console.log(JSON.stringify({ checked: checks.length, unmatched: report.unmatched }, null, 2));
