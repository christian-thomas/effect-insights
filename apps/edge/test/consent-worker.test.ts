import { env, createExecutionContext, waitOnExecutionContext } from 'cloudflare:test';
import { describe, it, expect, beforeEach } from 'vitest';
import worker from '../src/consent-worker';
import migration0001 from '../migrations/0001_consent_events.sql?raw';
import type { ConsentEventInput } from '@effectinsight/types/schemas/consent';

interface RateLimitBindingMock {
  limit(opts: { key: string }): Promise<{ success: boolean }>;
}

interface ConsentEnv {
  readonly CONSENT_DB: D1Database;
  readonly LARAVEL_INGEST_URL: string;
  readonly LARAVEL_INGEST_SECRET: string;
  readonly ENVIRONMENT: string;
  CONSENT_RATE_LIMITER?: RateLimitBindingMock;
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

  it('idempotently accepts a duplicate POST of the same event', async () => {
    const first = await fetchWorker({
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(sampleEvent),
    });
    expect(first.status).toBe(202);

    // Same event UUID — outbox retry on a previously-persisted event.
    // INSERT OR IGNORE should treat it as a silent success, not a 500.
    const second = await fetchWorker({
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(sampleEvent),
    });
    expect(second.status).toBe(202);

    const countRow = await typedEnv.CONSENT_DB.prepare(
      'SELECT COUNT(*) AS count FROM consent_events WHERE id = ?',
    )
      .bind(sampleEvent.id)
      .first<{ count: number }>();
    expect(countRow?.count).toBe(1);
  });

  it('returns 404 for unknown routes', async () => {
    const response = await fetchWorker({ method: 'POST' }, 'http://edge.test/nope');
    expect(response.status).toBe(404);
  });

  it('accepts a valid RUM beacon with 204 No Content', async () => {
    const response = await fetchWorker(
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          domainPublicKey: 'pk_test_abc123',
          paintMs: 42,
          hadStoredConsent: false,
          effectiveConnectionType: '4g',
          deviceMemoryGb: 8,
        }),
      },
      'http://edge.test/rum',
    );
    expect(response.status).toBe(204);
  });

  it('rejects a malformed RUM beacon with 400', async () => {
    const response = await fetchWorker(
      {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ paintMs: 'not-a-number' }),
      },
      'http://edge.test/rum',
    );
    expect(response.status).toBe(400);
  });

  describe('rate limiting', () => {
    it('returns 429 on /consent when the limiter denies the request', async () => {
      typedEnv.CONSENT_RATE_LIMITER = {
        limit: async (): Promise<{ success: boolean }> => ({ success: false }),
      };
      try {
        const response = await fetchWorker({
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(sampleEvent),
        });
        expect(response.status).toBe(429);
        expect(response.headers.get('Retry-After')).toBe('60');
      } finally {
        delete typedEnv.CONSENT_RATE_LIMITER;
      }
    });

    it('returns 429 on /rum when the limiter denies the request', async () => {
      typedEnv.CONSENT_RATE_LIMITER = {
        limit: async (): Promise<{ success: boolean }> => ({ success: false }),
      };
      try {
        const response = await fetchWorker(
          {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              domainPublicKey: 'pk_test',
              paintMs: 1,
              hadStoredConsent: false,
            }),
          },
          'http://edge.test/rum',
        );
        expect(response.status).toBe(429);
      } finally {
        delete typedEnv.CONSENT_RATE_LIMITER;
      }
    });

    it('treats a failing limiter as fail-open (accepts the event)', async () => {
      typedEnv.CONSENT_RATE_LIMITER = {
        limit: async (): Promise<{ success: boolean }> => {
          throw new Error('limiter unavailable');
        },
      };
      try {
        const response = await fetchWorker({
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            ...sampleEvent,
            id: '22222222-2222-4222-a222-222222222222',
          }),
        });
        expect(response.status).toBe(202);
      } finally {
        delete typedEnv.CONSENT_RATE_LIMITER;
      }
    });
  });
});
