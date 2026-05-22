import type { BannerConfig } from '@effect-cmp/types/banner';

interface Env {
  readonly BANNER_CONFIGS: KVNamespace;
  readonly BUNDLES: R2Bucket;
  readonly ENVIRONMENT: string;
}

const corsHeaders = (origin: string | null): Record<string, string> => ({
  'Access-Control-Allow-Origin': origin ?? '*',
  'Access-Control-Allow-Methods': 'GET, OPTIONS',
  'Access-Control-Max-Age': '86400',
  Vary: 'Origin',
});

const json = (
  data: unknown,
  status: number,
  origin: string | null,
): Response =>
  new Response(JSON.stringify(data), {
    status,
    headers: {
      'Content-Type': 'application/json',
      'Cache-Control': 'public, max-age=60, stale-while-revalidate=300',
      ...corsHeaders(origin),
    },
  });

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const url = new URL(request.url);
    const origin = request.headers.get('Origin');

    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: corsHeaders(origin) });
    }

    const configMatch = url.pathname.match(/^\/config\/([A-Za-z0-9_-]+)$/);
    if (configMatch && configMatch[1]) {
      const publicKey = configMatch[1];
      const raw = await env.BANNER_CONFIGS.get(publicKey);
      if (!raw) {
        return json({ error: 'not_found' }, 404, origin);
      }
      const config = JSON.parse(raw) as BannerConfig;
      const country = request.headers.get('CF-IPCountry');
      return json({ config, country }, 200, origin);
    }

    if (url.pathname === '/loader.js' || /^\/cmp\.[a-f0-9]+\.js$/.test(url.pathname)) {
      const key = url.pathname.replace(/^\//, '');
      const object = await env.BUNDLES.get(key);
      if (!object) {
        return new Response('not found', { status: 404 });
      }
      const cacheControl =
        key === 'loader.js'
          ? 'public, max-age=300, stale-while-revalidate=86400'
          : 'public, max-age=31536000, immutable';
      return new Response(object.body, {
        headers: {
          'Content-Type': 'application/javascript; charset=utf-8',
          'Cache-Control': cacheControl,
          ...corsHeaders(origin),
        },
      });
    }

    return new Response('not found', { status: 404 });
  },
} satisfies ExportedHandler<Env>;
