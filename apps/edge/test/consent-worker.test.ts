import { env, createExecutionContext, waitOnExecutionContext } from 'cloudflare:test';
import { describe, it, expect, beforeEach } from 'vitest';
import worker from '../src/consent-worker';
import migration0001 from '../migrations/0001_consent_events.sql?raw';
import type { ConsentEventInput } from '@effect-cmp/types/schemas/consent';

interface ConsentEnv {
  readonly CONSENT_DB: D1Database;
  readonly LARAVEL_INGEST_URL: string;
  readonly LARAVEL_INGEST_SECRET: string;
  readonly ENVIRONMENT: string;
}

const typedEnv = env as unknown as ConsentEnv;

const sampleEvent: ConsentEventInput = {
  id: '11111111-1111-4111-a111-111111111111',
  domainPublicKey: 'pk_test_abc123',
  bannerConfigVersion: 1,
  anonymousId: 'anon_abc',
  categories: {
    necessary: true,
    preferences: false,
    statistics: true,
    marketing: false,
  },
  gcmSignals: {
    analytics_storage: 'granted',
    ad_storage: 'denied',
  },
  countryCode: 'GB',
  userAgentHash: 'ua_hash',
  ipHash: 'ip_hash',
  eventType: 'granted',
  occurredAt: '2026-05-22T12:00:00Z',
  bannerTextHash: 'btxt_hash',
};

const migrationStatements: readonly string[] = migration0001
  .split(';')
  .map((statement: string): string =>
    statement
      .replace(/--[^\n]*/g, '')
      .replace(/\s+/g, ' ')
      .trim(),
  )
  .filter((statement: string): boolean => statement.length > 0);

const fetchWorker = async (
  init: RequestInit,
  url = 'http://edge.test/consent',
): Promise<Response> => {
  const ctx = createExecutionContext();
  const response = await worker.fetch(new Request(url, init), typedEnv, ctx);
  await waitOnExecutionContext(ctx);
  return response;
};

describe('consent worker', () => {
  beforeEach(async () => {
    for (const statement of migrationStatements) {
      // eslint-disable-next-line no-await-in-loop -- DDL must run sequentially: CREATE TABLE before CREATE INDEX
      await typedEnv.CONSENT_DB.exec(statement);
    }
  });

  it('accepts a valid consent event and writes it to D1', async () => {
    const response = await fetchWorker({
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(sampleEvent),
    });

    expect(response.status).toBe(202);
    const body = (await response.json()) as { ok: boolean };
    expect(body.ok).toBe(true);

    const row = await typedEnv.CONSENT_DB.prepare(
      'SELECT id, domain_public_key, event_type FROM consent_events WHERE id = ?',
    )
      .bind(sampleEvent.id)
      .first<{ id: string; domain_public_key: string; event_type: string }>();

    expect(row).not.toBeNull();
    expect(row?.domain_public_key).toBe('pk_test_abc123');
    expect(row?.event_type).toBe('granted');
  });

  it('rejects an invalid payload', async () => {
    const response = await fetchWorker({
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ...sampleEvent, id: 'not-a-uuid' }),
    });
    expect(response.status).toBe(400);
  });

  it('returns 404 for unknown routes', async () => {
    const response = await fetchWorker({ method: 'POST' }, 'http://edge.test/nope');
    expect(response.status).toBe(404);
  });
});
