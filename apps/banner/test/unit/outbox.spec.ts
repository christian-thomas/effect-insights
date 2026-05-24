import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { flushOutbox, queueOutboxEntry } from '../../src/outbox';
import type { ConsentPostBody } from '../../src/consent';

const sampleEvent = (overrides: Partial<ConsentPostBody> = {}): ConsentPostBody => ({
  id: 'evt-1',
  domainPublicKey: 'pk_test',
  bannerConfigVersion: 1,
  anonymousId: 'anon-1',
  categories: { necessary: true, preferences: false, statistics: true, marketing: false },
  gcmSignals: {
    analytics_storage: 'granted',
    ad_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    personalization_storage: 'denied',
    functionality_storage: 'granted',
    security_storage: 'granted',
  },
  countryCode: 'GB',
  userAgentHash: 'ua_hash',
  ipHash: 'ip_hash',
  eventType: 'granted',
  occurredAt: '2026-05-24T12:00:00Z',
  bannerTextHash: 'v1',
  ...overrides,
});

const fetchMock = vi.fn<typeof fetch>();

describe('outbox', () => {
  beforeEach(() => {
    localStorage.clear();
    fetchMock.mockReset();
    vi.stubGlobal('fetch', fetchMock);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  describe('queueOutboxEntry', () => {
    it('persists a queued entry to localStorage', () => {
      queueOutboxEntry(sampleEvent(), 'http://localhost:8788');

      const raw = localStorage.getItem('cmp_outbox');
      expect(raw).not.toBeNull();
      const parsed = JSON.parse(raw ?? '[]');
      expect(parsed).toHaveLength(1);
      expect(parsed[0].event.id).toBe('evt-1');
      expect(parsed[0].consentBaseUrl).toBe('http://localhost:8788');
      expect(parsed[0].attempts).toBe(0);
    });

    it('caps the queue at 100 entries, dropping the oldest', () => {
      for (let i = 0; i < 110; i++) {
        queueOutboxEntry(sampleEvent({ id: `evt-${i}` }), 'http://localhost:8788');
      }

      const raw = localStorage.getItem('cmp_outbox');
      const parsed = JSON.parse(raw ?? '[]');
      expect(parsed).toHaveLength(100);
      expect(parsed[0].event.id).toBe('evt-10');
      expect(parsed[99].event.id).toBe('evt-109');
    });
  });

  describe('flushOutbox', () => {
    it('is a no-op when the queue is empty', async () => {
      await flushOutbox();
      expect(fetchMock).not.toHaveBeenCalled();
    });

    it('removes successfully-POSTed entries from the queue', async () => {
      queueOutboxEntry(sampleEvent({ id: 'evt-A' }), 'http://localhost:8788');
      queueOutboxEntry(sampleEvent({ id: 'evt-B' }), 'http://localhost:8788');

      fetchMock.mockResolvedValue(new Response('{"ok":true}', { status: 202 }));

      await flushOutbox();

      expect(fetchMock).toHaveBeenCalledTimes(2);
      expect(localStorage.getItem('cmp_outbox')).toBeNull();
    });

    it('keeps entries that fail with 5xx and increments their attempts counter', async () => {
      queueOutboxEntry(sampleEvent({ id: 'evt-X' }), 'http://localhost:8788');

      fetchMock.mockResolvedValue(new Response('', { status: 503 }));

      await flushOutbox();

      const remaining = JSON.parse(localStorage.getItem('cmp_outbox') ?? '[]');
      expect(remaining).toHaveLength(1);
      expect(remaining[0].attempts).toBe(1);
    });

    it('drops entries that exceed the max attempts cap', async () => {
      // Seed an entry that's already at MAX_ATTEMPTS - 1 attempts.
      localStorage.setItem(
        'cmp_outbox',
        JSON.stringify([
          {
            event: sampleEvent({ id: 'evt-final' }),
            consentBaseUrl: 'http://localhost:8788',
            attempts: 4,
            queuedAt: '2026-05-24T00:00:00Z',
          },
        ]),
      );

      fetchMock.mockResolvedValue(new Response('', { status: 503 }));

      await flushOutbox();

      expect(localStorage.getItem('cmp_outbox')).toBeNull();
    });

    it('retries entries on network errors', async () => {
      queueOutboxEntry(sampleEvent({ id: 'evt-net' }), 'http://localhost:8788');

      fetchMock.mockRejectedValue(new TypeError('NetworkError'));

      await flushOutbox();

      const remaining = JSON.parse(localStorage.getItem('cmp_outbox') ?? '[]');
      expect(remaining).toHaveLength(1);
      expect(remaining[0].attempts).toBe(1);
    });

    it('drops 4xx responses immediately (permanent failure)', async () => {
      queueOutboxEntry(sampleEvent({ id: 'evt-bad' }), 'http://localhost:8788');

      fetchMock.mockResolvedValue(new Response('{"error":"bad shape"}', { status: 400 }));

      await flushOutbox();

      // 4xx doesn't throw in postConsentEvent so the entry is considered
      // delivered (server has rejected; no point retrying).
      expect(localStorage.getItem('cmp_outbox')).toBeNull();
    });
  });
});
