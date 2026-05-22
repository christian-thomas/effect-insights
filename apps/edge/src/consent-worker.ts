import type { ConsentEvent } from '@effect-cmp/types/consent';

interface Env {
  readonly CONSENT_DB: D1Database;
  readonly LARAVEL_INGEST_URL: string;
  readonly ENVIRONMENT: string;
}

const corsHeaders = (origin: string | null): Record<string, string> => ({
  'Access-Control-Allow-Origin': origin ?? '*',
  'Access-Control-Allow-Methods': 'POST, OPTIONS',
  'Access-Control-Allow-Headers': 'Content-Type',
  'Access-Control-Max-Age': '86400',
  Vary: 'Origin',
});

const json = (data: unknown, status: number, origin: string | null): Response =>
  new Response(JSON.stringify(data), {
    status,
    headers: {
      'Content-Type': 'application/json',
      ...corsHeaders(origin),
    },
  });

const persistToD1 = async (
  db: D1Database,
  event: ConsentEvent,
): Promise<void> => {
  await db
    .prepare(
      `INSERT INTO consent_events (
        id, domain_public_key, banner_config_version, anonymous_id,
        categories_json, gcm_signals_json, country_code, ua_hash,
        ip_hash, event_type, occurred_at, banner_text_hash
      ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    )
    .bind(
      event.id,
      event.domainPublicKey,
      event.bannerConfigVersion,
      event.anonymousId,
      JSON.stringify(event.categories),
      JSON.stringify(event.gcmSignals),
      event.countryCode,
      event.userAgentHash,
      event.ipHash,
      event.eventType,
      event.occurredAt,
      event.bannerTextHash,
    )
    .run();
};

const mirrorToLaravel = async (
  url: string,
  event: ConsentEvent,
): Promise<void> => {
  // Fire-and-forget — Laravel is the backup channel, edge must not block on it.
  try {
    await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(event),
    });
  } catch {
    // Edge already has the canonical write; mirror is best-effort.
  }
};

export default {
  async fetch(
    request: Request,
    env: Env,
    ctx: ExecutionContext,
  ): Promise<Response> {
    const origin = request.headers.get('Origin');

    if (request.method === 'OPTIONS') {
      return new Response(null, { status: 204, headers: corsHeaders(origin) });
    }

    if (request.method !== 'POST') {
      return json({ error: 'method_not_allowed' }, 405, origin);
    }

    let event: ConsentEvent;
    try {
      event = (await request.json()) as ConsentEvent;
    } catch {
      return json({ error: 'invalid_json' }, 400, origin);
    }

    try {
      await persistToD1(env.CONSENT_DB, event);
    } catch {
      return json({ error: 'persist_failed' }, 500, origin);
    }

    ctx.waitUntil(mirrorToLaravel(env.LARAVEL_INGEST_URL, event));

    return json({ ok: true }, 202, origin);
  },
} satisfies ExportedHandler<Env>;
