/// <reference types="bun-types" />

import { existsSync, statSync } from 'node:fs';
import { resolve } from 'node:path';

const ROOT = resolve(import.meta.dir, '..');

const contentTypeFor = (path: string): string => {
  if (path.endsWith('.html')) return 'text/html; charset=utf-8';
  if (path.endsWith('.js') || path.endsWith('.mjs')) return 'application/javascript; charset=utf-8';
  if (path.endsWith('.css')) return 'text/css; charset=utf-8';
  if (path.endsWith('.json')) return 'application/json; charset=utf-8';
  if (path.endsWith('.map')) return 'application/json; charset=utf-8';
  return 'application/octet-stream';
};

const server = Bun.serve({
  port: Number.parseInt(process.env.PORT ?? '4173', 10),
  hostname: '127.0.0.1',
  fetch(req) {
    const url = new URL(req.url);
    const requestPath = url.pathname === '/'
      ? '/test/e2e/fixtures/host.html'
      : url.pathname;
    const filePath = resolve(ROOT, '.' + requestPath);

    if (!filePath.startsWith(ROOT)) {
      return new Response('forbidden', { status: 403 });
    }
    if (!existsSync(filePath) || statSync(filePath).isDirectory()) {
      return new Response('not found', { status: 404 });
    }
    return new Response(Bun.file(filePath), {
      headers: { 'Content-Type': contentTypeFor(filePath) },
    });
  },
});

console.info(`Banner fixture server listening on http://${server.hostname}:${server.port}`);
