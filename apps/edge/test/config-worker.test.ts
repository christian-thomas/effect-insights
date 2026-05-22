import {
  env,
  createExecutionContext,
  waitOnExecutionContext,
} from 'cloudflare:test';
import { describe, it, expect, beforeEach } from 'vitest';
import worker from '../src/config-worker';

interface ConfigEnv {
  readonly BANNER_CONFIGS: KVNamespace;
  readonly BUNDLES: R2Bucket;
  readonly ENVIRONMENT: string;
}

const typedEnv = env as unknown as ConfigEnv;

const sampleConfig = {
  domainPublicKey: 'pk_test_abc123',
  version: 1,
  localeDefault: 'en',
  localesEnabled: ['en'],
  layout: 'modal' as const,
  granularity: 'categorised' as const,
  colors: {
    background: '#fff',
    text: '#111',
    primary: '#06f',
    primaryText: '#fff',
    secondary: '#eee',
    secondaryText: '#111',
  },
  copy: {},
  geoRules: { mode: 'always' as const },
  gcmDefaults: {
    ad_storage: 'denied' as const,
    analytics_storage: 'denied' as const,
    ad_user_data: 'denied' as const,
    ad_personalization: 'denied' as const,
    functionality_storage: 'granted' as const,
    personalization_storage: 'denied' as const,
    security_storage: 'granted' as const,
  },
  behaviour: {
    rePromptAfterDays: 365,
    honourGpc: true,
    autoDenyOnDismiss: false,
    crossSubdomainConsent: false,
  },
  publishedAt: '2026-05-22T00:00:00Z',
  signature: 'stub',
};

const fetchWorker = async (
  url: string,
  init?: RequestInit,
): Promise<Response> => {
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

  it('returns the stored config wrapped with the country header', async () => {
    await typedEnv.BANNER_CONFIGS.put(
      'pk_test_abc123',
      JSON.stringify(sampleConfig),
    );
    const response = await fetchWorker(
      'http://edge.test/config/pk_test_abc123',
      { headers: { 'CF-IPCountry': 'GB' } },
    );
    expect(response.status).toBe(200);
    const body = (await response.json()) as {
      config: typeof sampleConfig;
      country: string | null;
    };
    expect(body.config.domainPublicKey).toBe('pk_test_abc123');
    expect(body.country).toBe('GB');
  });

  it('rejects unknown routes', async () => {
    const response = await fetchWorker('http://edge.test/nope');
    expect(response.status).toBe(404);
  });
});
