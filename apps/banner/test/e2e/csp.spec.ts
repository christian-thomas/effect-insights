import { expect, test, type Page, type Request } from '@playwright/test';

const sampleConfig = {
  domainPublicKey: 'pk_test_abc123',
  version: 1,
  materialVersion: 1,
  localeDefault: 'en',
  localesEnabled: ['en'],
  layout: 'modal',
  granularity: 'categorised',
  colors: {
    background: '#ffffff',
    text: '#111827',
    primary: '#2563eb',
    primaryText: '#ffffff',
    secondary: '#f3f4f6',
    secondaryText: '#111827',
  },
  copy: {
    en: {
      title: 'We use cookies',
      body: 'A short description.',
      acceptAllLabel: 'Accept all',
      rejectAllLabel: 'Reject all',
      customiseLabel: 'Customise',
      savePreferencesLabel: 'Save preferences',
      categoryDescriptions: {
        necessary: 'Essential for the site.',
        preferences: 'Remember choices.',
        statistics: 'Anonymous analytics.',
        marketing: 'Personalised ads.',
      },
    },
  },
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
  signature: 'unsigned',
};

const setupRoutes = async (page: Page): Promise<void> => {
  await page.route('**/assets.effectcmp.com/config/*', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ config: sampleConfig, country: 'GB' }),
    });
  });

  await page.route('**/assets.effectcmp.com/consent', async (route, _request: Request) => {
    await route.fulfill({
      status: 202,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true }),
    });
  });
};

const NONCE = 'csp-test-nonce-123';

test.describe('banner under strict CSP', () => {
  test('renders the banner with a nonce-stamped style element', async ({ page }) => {
    await setupRoutes(page);

    const cspViolations: string[] = [];
    page.on('console', (message) => {
      const text = message.text();
      if (text.toLowerCase().includes('content security policy')) {
        cspViolations.push(text);
      }
    });

    await page.goto('/test/e2e/fixtures/host-csp-strict.html');
    await page.locator('.effectcmp-card').waitFor({ state: 'visible' });

    // The injected <style> must carry the page nonce so the browser applies
    // it under the strict CSP. Reading via attribute confirms the nonce is
    // present in the DOM (the browser strips it as a property, but our
    // applyNonce() helper sets both).
    const styleNonce = await page.locator('[role="dialog"] style').first().getAttribute('nonce');
    expect(styleNonce).toBe(NONCE);

    expect(cspViolations, `Console reported CSP violations:\n${cspViolations.join('\n')}`).toEqual(
      [],
    );
  });

  test('released scripts execute under strict CSP', async ({ page }) => {
    await setupRoutes(page);

    await page.goto('/test/e2e/fixtures/host-csp-strict.html');
    await page.locator('.effectcmp-card').waitFor({ state: 'visible' });

    await page.getByRole('button', { name: 'Accept all' }).click();

    // The two blocked scripts on the fixture page set window.__csp*Fired
    // when they execute. If cmp.js didn't propagate the page nonce onto
    // the re-injected <script> elements, the browser would block them and
    // these flags would never be set.
    await expect
      .poll(() =>
        page.evaluate(() => (window as unknown as Record<string, unknown>).__cspStatsFired),
      )
      .toBe(true);
    await expect
      .poll(() =>
        page.evaluate(() => (window as unknown as Record<string, unknown>).__cspMarketingFired),
      )
      .toBe(true);
  });
});
