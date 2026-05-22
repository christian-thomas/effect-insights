import { Hono } from 'hono';
import { cors } from 'hono/cors';
import type { Context } from 'hono';
import type { BannerConfig } from '@effect-cmp/types/banner';

type Bindings = {
  readonly BANNER_CONFIGS: KVNamespace;
  readonly BUNDLES: R2Bucket;
  readonly ENVIRONMENT: string;
};

const app = new Hono<{ Bindings: Bindings }>();

app.use('*', cors({ origin: '*', allowMethods: ['GET', 'OPTIONS'] }));

app.get('/config/:publicKey{[A-Za-z0-9_-]+}', async (c) => {
  const publicKey = c.req.param('publicKey');
  const raw = await c.env.BANNER_CONFIGS.get(publicKey);
  if (!raw) {
    return c.json({ error: 'not_found' }, 404);
  }
  const config = JSON.parse(raw) as BannerConfig;
  c.header(
    'Cache-Control',
    'public, max-age=60, stale-while-revalidate=300',
  );
  return c.json({
    config,
    country: c.req.header('CF-IPCountry') ?? null,
  });
});

const serveBundle = async (
  c: Context<{ Bindings: Bindings }>,
  key: string,
  cachePolicy: 'short' | 'immutable',
): Promise<Response> => {
  const object = await c.env.BUNDLES.get(key);
  if (!object) {
    return c.text('not found', 404);
  }
  const cacheControl =
    cachePolicy === 'immutable'
      ? 'public, max-age=31536000, immutable'
      : 'public, max-age=300, stale-while-revalidate=86400';
  return new Response(object.body, {
    headers: {
      'Content-Type': 'application/javascript; charset=utf-8',
      'Cache-Control': cacheControl,
    },
  });
};

app.get('/loader.js', (c) => serveBundle(c, 'loader.js', 'short'));
app.get('/cmp.:hash{[a-f0-9]+}.js', (c) =>
  serveBundle(c, `cmp.${c.req.param('hash')}.js`, 'immutable'),
);

app.notFound((c) => c.text('not found', 404));

export default app;
