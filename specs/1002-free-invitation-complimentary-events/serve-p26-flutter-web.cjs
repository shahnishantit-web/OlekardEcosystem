const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');

const root = fs.realpathSync(process.argv[2]);
const port = Number(process.argv[3]);
if (!Number.isInteger(port) || port < 1 || port > 65535) {
  throw new Error('Invalid isolated P26 web port.');
}

const contentTypes = new Map([
  ['.css', 'text/css; charset=utf-8'],
  ['.html', 'text/html; charset=utf-8'],
  ['.ico', 'image/x-icon'],
  ['.js', 'text/javascript; charset=utf-8'],
  ['.json', 'application/json; charset=utf-8'],
  ['.png', 'image/png'],
  ['.svg', 'image/svg+xml'],
  ['.ttf', 'font/ttf'],
  ['.wasm', 'application/wasm'],
  ['.webp', 'image/webp'],
]);

http.createServer((request, response) => {
  let pathname;
  try {
    pathname = decodeURIComponent(new URL(request.url ?? '/', 'http://localhost').pathname);
  } catch {
    response.writeHead(400).end();
    return;
  }

  const relative = pathname.replace(/^\/+/, '');
  let candidate = path.resolve(root, relative || 'index.html');
  if (candidate !== root && !candidate.startsWith(`${root}${path.sep}`)) {
    response.writeHead(404).end();
    return;
  }
  if (!fs.existsSync(candidate) || !fs.statSync(candidate).isFile()) {
    candidate = path.join(root, 'index.html');
  }
  if (!candidate.startsWith(`${root}${path.sep}`)) {
    response.writeHead(404).end();
    return;
  }

  response.writeHead(200, {
    'Cache-Control': 'no-store',
    'Content-Type': contentTypes.get(path.extname(candidate).toLowerCase()) ?? 'application/octet-stream',
    'X-Content-Type-Options': 'nosniff',
  });
  fs.createReadStream(candidate).pipe(response);
}).listen(port, '127.0.0.1');
