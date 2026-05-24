import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  collectNavigatorSignals,
  sendRumBeacon,
  startPaintTimer,
  type RumBeacon,
} from '../../src/rum';

const fetchMock = vi.fn<typeof fetch>();

describe('rum.startPaintTimer', () => {
  it('returns the elapsed milliseconds rounded to an integer', () => {
    const timer = startPaintTimer();
    const elapsed = timer.elapsed();
    expect(elapsed).toBeGreaterThanOrEqual(0);
    expect(Number.isInteger(elapsed)).toBe(true);
  });
});

describe('rum.sendRumBeacon', () => {
  beforeEach(() => {
    fetchMock.mockReset();
    fetchMock.mockResolvedValue(new Response(null, { status: 204 }));
    vi.stubGlobal('fetch', fetchMock);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('POSTs the beacon to /rum on the consent base URL with keepalive', () => {
    const beacon: RumBeacon = {
      domainPublicKey: 'pk_test',
      paintMs: 17,
      hadStoredConsent: false,
    };

    sendRumBeacon('http://127.0.0.1:8788', beacon);

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0]!;
    expect(url).toBe('http://127.0.0.1:8788/rum');
    expect(init?.method).toBe('POST');
    expect(init?.keepalive).toBe(true);
    expect(init?.body).toBe(JSON.stringify(beacon));
  });

  it('swallows fetch rejections silently (best-effort)', () => {
    fetchMock.mockRejectedValue(new TypeError('NetworkError'));

    expect(() =>
      sendRumBeacon('http://127.0.0.1:8788', {
        domainPublicKey: 'pk_test',
        paintMs: 5,
        hadStoredConsent: true,
      }),
    ).not.toThrow();
  });

  it('swallows synchronous fetch failures', () => {
    vi.stubGlobal('fetch', () => {
      throw new Error('fetch unavailable');
    });

    expect(() =>
      sendRumBeacon('http://127.0.0.1:8788', {
        domainPublicKey: 'pk_test',
        paintMs: 5,
        hadStoredConsent: true,
      }),
    ).not.toThrow();
  });
});

describe('rum.collectNavigatorSignals', () => {
  it('returns whatever extras the navigator exposes', () => {
    const result = collectNavigatorSignals();
    // happy-dom doesn't expose connection or deviceMemory, so both come
    // back undefined — the function must still return an object.
    expect(result).toBeTypeOf('object');
  });
});
