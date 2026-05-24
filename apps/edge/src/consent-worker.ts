import { Hono } from 'hono';
import { cors } from 'hono/cors';
import { zValidator } from '@hono/zod-validator';
import { z } from 'zod';
import { consentEventSchema } from '@effect-cmp/types/schemas/consent';
import type { ConsentEventInput } from '@effect-cmp/types/schemas/consent';

// Minimal RUM payload — cmp.js posts one per banner render so we can
// track paint-time p95 against the <50ms budget. Schema is intentionally
// loose; we just want to drop obviously-malformed bodies on the floor.
const rumBeaconSchema = z.object({
  domainPublicKey: z.string().min(1).max(128),
  paintMs: z.number().nonnegative().max(60_000),
  hadStoredConsent: z.boolean(),
  effectiveConnectionType: z.string().max(16).optional(),
  deviceMemoryGb: z.number().optional(),
});

type Bindings = {
  readonly CONSENT_DB: D1Database;
  readonly LARAVEL_INGEST_URL: string;
  readonly LARAVEL_INGEST_SECRET: string;
  readonly ENVIRONMENT: string;
};

const app = new Hono<{ Bindings: Bindings }>();

app.use(
  '*',
  cors({
    origin: '*',
    allowMethods: ['POST', 'OPTIONS'],
    allowHeaders: ['Content-Type'],
  }),
);

const persistToD1 = async (db: D1Database, event: ConsentEventInput): Promise<void> => {
  // INSERT OR IGNORE so the cmp.js outbox can retry a POST without 500ing on
  // the second attempt: the row was already written, treat it as success.
  // The client-generated UUID is what makes this safe — repeated submissions
  // of the same event are byte-identical.
  await db
    .prepare(
      `INSERT OR IGNORE INTO consent_events (
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
  secret: string,
  event: ConsentEventInput,
): Promise<void> => {
  try {
    await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Effect-Ingest-Secret': secret,
      },
      body: JSON.stringify(event),
    });
  } catch {
    // Edge already has the canonical write; mirror is best-effort.
  }
};

app.post('/consent', zValidator('json', consentEventSchema), async (c) => {
  const event = c.req.valid('json');

  try {
    await persistToD1(c.env.CONSENT_DB, event);
  } catch {
    return c.json({ error: 'persist_failed' }, 500);
  }

  c.executionCtx.waitUntil(
    mirrorToLaravel(c.env.LARAVEL_INGEST_URL, c.env.LARAVEL_INGEST_SECRET, event),
  );

  return c.json({ ok: true }, 202);
});

// RUM beacon sink. Logs each valid beacon as a single JSON line so
// `wrangler tail --format=json` and CF Logpush capture it. No DB write
// yet — server-side aggregation is a separate piece. Always 204 even
// on malformed beacons so cmp.js never retries the call.
app.post('/rum', zValidator('json', rumBeaconSchema), async (c) => {
  const beacon = c.req.valid('json');
  // Structured-log shape that's easy to grep / aggregate downstream.
  // console.log is the intended sink here — `wrangler tail --format=json`
  // and CF Logpush both capture it.
  // eslint-disable-next-line no-console -- intentional log sink for RUM
  console.log(JSON.stringify({ type: 'cmp_rum', ...beacon }));
  return new Response(null, { status: 204 });
});

app.notFound((c) => c.json({ error: 'not_found' }, 404));

export default app;
