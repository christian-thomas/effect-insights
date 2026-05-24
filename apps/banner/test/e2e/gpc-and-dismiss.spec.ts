import { expect, test, type Page, type Request } from '@playwright/test';

const baseConfig = {
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
    honourGpc: false,
    autoDenyOnDismiss: false,
    crossSubdomainConsent: false,
  },
  publishedAt: '2026-05-22T00:00:00Z',
  signature: 'unsigned',
};

interface ConsentPostCaptor {
  body: Record<string, unknown> | null;
}

const setupRoutes = async (
  page: Page,
  captor: ConsentPostCaptor,
  configOverrides: Partial<typeof baseConfig.behaviour> = {},
): Promise<void> => {
  const config = {
    ...baseConfig,
    behaviour: { ...baseConfig.behaviour, ...configOverrides },
  };
  await page.route('**/assets.tracewatch.io/config/*', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ config, country: 'GB' }),
    });
  });
  await page.route('**/assets.tracewatch.io/consent', async (route, request: Request) => {
    captor.body = JSON.parse(request.postData() ?? '{}') as Record<string, unknown>;
    await route.fulfill({
      status: 202,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true }),
    });
  });
};

const fakeGpc = async (page: Page, enabled: boolean): Promise<void> => {
  await page.addInitScript((isOn: boolean): void => {
    Object.defineProperty(navigator, 'globalPrivacyControl', {
      get: () => isOn,
      configurable: true,
    });
  }, enabled);
};

test.describe('Global Privacy Control', () => {
  test('records a denied event and hides the banner when GPC is on and honourGpc is enabled', async ({
    page,
  }) => {
    const captor: ConsentPostCaptor = { body: null };
    await setupRoutes(page, captor, { honourGpc: true });
    await fakeGpc(page, true);

    await page.goto('/');

    // Banner should NOT render — auto-deny short-circuit.
    await expect(page.locator('.tracewatch-card')).toBeHidden();

    // A consent event should be POSTed with eventType=denied.
    await expect.poll(() => captor.body).not.toBeNull();
    expect(captor.body?.eventType).toBe('denied');
    const categories = captor.body?.categories as Record<string, boolean>;
    expect(categories.statistics).toBe(false);
    expect(categories.marketing).toBe(false);
  });

  test('renders the banner when GPC is on but honourGpc is disabled', async ({ page }) => {
    const captor: ConsentPostCaptor = { body: null };
    await setupRoutes(page, captor, { honourGpc: false });
    await fakeGpc(page, true);

    await page.goto('/');

    await expect(page.locator('.tracewatch-card')).toBeVisible();
    expect(captor.body).toBeNull();
  });

  test('renders the banner when honourGpc is on but the browser is not sending GPC', async ({
    page,
  }) => {
    const captor: ConsentPostCaptor = { body: null };
    await setupRoutes(page, captor, { honourGpc: true });
    await fakeGpc(page, false);

    await page.goto('/');

    await expect(page.locator('.tracewatch-card')).toBeVisible();
    expect(captor.body).toBeNull();
  });
});

test.describe('autoDenyOnDismiss', () => {
  test('Escape on the dialog records denied + closes when autoDenyOnDismiss is on', async ({
    page,
  }) => {
    const captor: ConsentPostCaptor = { body: null };
    await setupRoutes(page, captor, { autoDenyOnDismiss: true });

    await page.goto('/');
    await page.locator('.tracewatch-card').waitFor({ state: 'visible' });
    await page.keyboard.press('Escape');
    await expect(page.locator('.tracewatch-card')).toBeHidden();

    await expect.poll(() => captor.body).not.toBeNull();
    expect(captor.body?.eventType).toBe('denied');
  });

  test('clicking the backdrop records denied + closes when autoDenyOnDismiss is on', async ({
    page,
  }) => {
    const captor: ConsentPostCaptor = { body: null };
    await setupRoutes(page, captor, { autoDenyOnDismiss: true });

    await page.goto('/');
    await page.locator('.tracewatch-card').waitFor({ state: 'visible' });

    // Click the shell wrapper outside the card. Use force=true with a
    // position on the far edge so we don't hit the card.
    const shell = page.locator('.tracewatch-shell');
    const box = await shell.boundingBox();
    expect(box).not.toBeNull();
    if (box) {
      // Top-left corner of the shell is definitely backdrop, not card.
      await page.mouse.click(box.x + 5, box.y + 5);
    }

    await expect(page.locator('.tracewatch-card')).toBeHidden();
    await expect.poll(() => captor.body).not.toBeNull();
    expect(captor.body?.eventType).toBe('denied');
  });

  test('Escape on the dialog is a no-op when autoDenyOnDismiss is off (default)', async ({
    page,
  }) => {
    const captor: ConsentPostCaptor = { body: null };
    await setupRoutes(page, captor, { autoDenyOnDismiss: false });

    await page.goto('/');
    await page.locator('.tracewatch-card').waitFor({ state: 'visible' });
    await page.keyboard.press('Escape');
    // Banner stays open + no consent recorded.
    await page.waitForTimeout(150);
    await expect(page.locator('.tracewatch-card')).toBeVisible();
    expect(captor.body).toBeNull();
  });

  test('Escape inside the customise pane still just collapses (no dismiss)', async ({ page }) => {
    const captor: ConsentPostCaptor = { body: null };
    await setupRoutes(page, captor, { autoDenyOnDismiss: true });

    await page.goto('/');
    await page.getByRole('button', { name: 'Customise' }).click();
    await expect(page.locator('.tracewatch-categories')).toBeVisible();

    await page.keyboard.press('Escape');
    // Customise pane collapses, banner stays open, no consent recorded.
    await expect(page.locator('.tracewatch-categories')).toBeHidden();
    await expect(page.locator('.tracewatch-card')).toBeVisible();
    expect(captor.body).toBeNull();
  });
});
