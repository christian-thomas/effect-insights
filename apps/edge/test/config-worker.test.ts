import { env, createExecutionContext, waitOnExecutionContext } from 'cloudflare:test';
import { describe, it, expect, beforeEach } from 'vitest';
import worker from '../src/config-worker';
import { signBannerConfigPayload } from '../src/banner-config-signing';

interface ConfigEnv {
  readonly BANNER_CONFIGS: KVNamespace;
  readonly BUNDLES: R2Bucket;
  readonly BANNER_CONFIG_SIGNING_KEY: string;
  readonly ENVIRONMENT: string;
}

const typedEnv = env as unknown as ConfigEnv;

const samplePayload = {
  domainPublicKey: 'pk_test_abc123',
  version: 1,
  materialVersion: 1,
  localeDefault: 'en',
  localesEnabled: ['en'],
  layout: 'modal',
  granularity: 'categorised',
  colors: {
    background: '#fff',
    text: '#111',
    primary: '#06f',
    primaryText: '#fff',
    secondary: '#eee',
    secondaryText: '#111',
  },
  copy: {},
  geoRules: { mode: 'always', countries: [] },
  gcmDefaults: {
    ad_storage: 'denied',
    analytics_storage: 'denied',
    ad_user_data: 'denied',
    ad_personalization: 'denied',
    functionality_storage: 'granted',
    personalization_storage: 'denied',
    security_storage: 'granted',
  },
  behaviour: {
    rePromptAfterDays: 365,
    honourGpc: true,
    autoDenyOnDismiss: false,
    crossSubdomainConsent: false,
  },
  publishedAt: '2026-05-22T00:00:00Z',
};

const storeSigned = async (
  publicKey: string,
  override: Partial<typeof samplePayload> = {},
): Promise<void> => {
  const payload = { ...samplePayload, ...override };
  const signature = await signBannerConfigPayload(payload, typedEnv.BANNER_CONFIG_SIGNING_KEY);
  await typedEnv.BANNER_CONFIGS.put(publicKey, JSON.stringify({ ...payload, signature }));
};

const fetchWorker = async (url: string, init?: RequestInit): Promise<Response> => {
  const ctx = createExecutionContext();
  const response = await worker.fetch(new Request(url, init), typedEnv, ctx);
  await waitOnExecutionContext(ctx);
  return response;
};

describe('config worker', () => {
  beforeEach(async () => {
    await typedEnv.BANNER_CONFIGS.delete('pk_test_abc123');
  });

  it('returns 404 for an unknown public key', async () => {
    const response = await fetchWorker('http://edge.test/config/pk_unknown');
    expect(response.status).toBe(404);
  });

  it('returns the stored config wrapped with the country header when signed', async () => {
    await storeSigned('pk_test_abc123');

    const response = await fetchWorker('http://edge.test/config/pk_test_abc123', {
      headers: { 'CF-IPCountry': 'GB' },
    });

    expect(response.status).toBe(200);
    const body = (await response.json()) as {
      config: { domainPublicKey: string };
      country: string | null;
    };
    expect(body.config.domainPublicKey).toBe('pk_test_abc123');
    expect(body.country).toBe('GB');
  });

  it('rejects a tampered payload with 503', async () => {
    // Sign with the real payload, then tamper before storing.
    const signature = await signBannerConfigPayload(
      samplePayload,
      typedEnv.BANNER_CONFIG_SIGNING_KEY,
    );
    const tampered = {
      ...samplePayload,
      gcmDefaults: { ...samplePayload.gcmDefaults, analytics_storage: 'granted' },
      signature,
    };
    await typedEnv.BANNER_CONFIGS.put('pk_test_abc123', JSON.stringify(tampered));

    const response = await fetchWorker('http://edge.test/config/pk_test_abc123');
    expect(response.status).toBe(503);
    const body = (await response.json()) as { error: string };
    expect(body.error).toBe('invalid_signature');
  });

  it('rejects a payload with a legacy unsigned marker', async () => {
    await typedEnv.BANNER_CONFIGS.put(
      'pk_test_abc123',
      JSON.stringify({ ...samplePayload, signature: 'unsigned' }),
    );

    const response = await fetchWorker('http://edge.test/config/pk_test_abc123');
    expect(response.status).toBe(503);
  });

  it('rejects unknown routes', async () => {
    const response = await fetchWorker('http://edge.test/nope');
    expect(response.status).toBe(404);
  });
});
