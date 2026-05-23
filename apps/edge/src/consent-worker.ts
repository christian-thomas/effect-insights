import { Hono } from 'hono';
import { cors } from 'hono/cors';
import { zValidator } from '@hono/zod-validator';
import { consentEventSchema } from '@effect-cmp/types/schemas/consent';
import type { ConsentEventInput } from '@effect-cmp/types/schemas/consent';

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

app.notFound((c) => c.json({ error: 'not_found' }, 404));

export default app;
