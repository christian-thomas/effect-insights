import { test, expect } from '@playwright/test';

const sampleConfig = {
  domainPublicKey: 'pk_test_abc123',
  version: 1,
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
  geoRules: { mode: 'always' },
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
  signature: 'stub',
};

test('banner boots and logs the domain key when config resolves', async ({ page }) => {
  await page.route('**/assets.effectcmp.com/config/*', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ config: sampleConfig, country: 'GB' }),
    });
  });

  const bootedPromise = page.waitForEvent('console', {
    predicate: (msg) => msg.text().includes('booted for pk_test_abc123'),
    timeout: 5_000,
  });

  await page.goto('host.html');
  const booted = await bootedPromise;
  expect(booted.text()).toContain('pk_test_abc123');
});

test('banner bails silently if config returns 404', async ({ page }) => {
  await page.route('**/assets.effectcmp.com/config/*', async (route) => {
    await route.fulfill({ status: 404, body: 'not found' });
  });

  const pageErrors: string[] = [];
  page.on('pageerror', (err) => pageErrors.push(err.message));

  await page.goto('host.html');
  await page.waitForTimeout(300);

  expect(pageErrors).toHaveLength(0);
});
