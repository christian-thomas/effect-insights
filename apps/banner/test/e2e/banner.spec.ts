import { test, expect, type Page, type Request } from '@playwright/test';

const sampleConfig = {
  domainPublicKey: 'pk_test_abc123',
  version: 1,
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

interface ConsentPostCaptor {
  body: Record<string, unknown> | null;
}

const setupRoutes = async (page: Page, captor: ConsentPostCaptor): Promise<void> => {
  await page.route('**/assets.effectcmp.com/config/*', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ config: sampleConfig, country: 'GB' }),
    });
  });

  await page.route('**/assets.effectcmp.com/consent', async (route, request: Request) => {
    captor.body = JSON.parse(request.postData() ?? '{}') as Record<string, unknown>;
    await route.fulfill({
      status: 202,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true }),
    });
  });
};

test('renders modal with copy from the config and dataLayer gets defaults', async ({ page }) => {
  const captor: ConsentPostCaptor = { body: null };
  await setupRoutes(page, captor);

  await page.goto('/');

  await expect(page.locator('.effectcmp-card')).toBeVisible();
  await expect(page.locator('.effectcmp-title')).toHaveText('We use cookies');
  await expect(page.getByRole('button', { name: 'Accept all' })).toBeVisible();

  const dataLayer = await page.evaluate(
    () => (window as unknown as { dataLayer?: unknown[] }).dataLayer,
  );
  expect(dataLayer).toBeDefined();
  const first = (dataLayer as unknown[])[0] as unknown[];
  expect(first[0]).toBe('consent');
  expect(first[1]).toBe('default');
});

test('accept all writes cookie, fires update, posts consent event', async ({ page }) => {
  const captor: ConsentPostCaptor = { body: null };
  await setupRoutes(page, captor);

  await page.goto('/');
  await page.getByRole('button', { name: 'Accept all' }).click();
  await expect(page.locator('.effectcmp-card')).toBeHidden();

  // Cookie set with our consent record. Read via document.cookie since
  // file:// origins don't surface through page.context().cookies().
  const cookieValue = await page.evaluate(() => {
    const match = document.cookie.match(/(?:^|;\s*)_effectcmp=([^;]+)/);
    return match ? decodeURIComponent(match[1] ?? '') : null;
  });
  expect(cookieValue).not.toBeNull();
  const stored = JSON.parse(cookieValue ?? '');
  expect(stored.bannerConfigVersion).toBe(1);
  expect(stored.categories.statistics).toBe(true);
  expect(stored.categories.marketing).toBe(true);

  // GCM update push happened.
  const dataLayer = await page.evaluate(
    () => (window as unknown as { dataLayer?: unknown[] }).dataLayer,
  );
  const updatePush = (dataLayer as unknown[]).find((entry) => {
    const arr = entry as unknown[];
    return arr[0] === 'consent' && arr[1] === 'update';
  }) as unknown[] | undefined;
  expect(updatePush).toBeDefined();
  const updatePayload = updatePush?.[2] as Record<string, string>;
  expect(updatePayload.analytics_storage).toBe('granted');
  expect(updatePayload.ad_storage).toBe('granted');

  // POST to /consent fired with the expected shape.
  await expect.poll(() => captor.body).not.toBeNull();
  expect(captor.body?.eventType).toBe('granted');
  expect(captor.body?.domainPublicKey).toBe('pk_test_abc123');
  const categories = captor.body?.categories as Record<string, boolean>;
  expect(categories.statistics).toBe(true);
});

test('reject all posts a denied event', async ({ page }) => {
  const captor: ConsentPostCaptor = { body: null };
  await setupRoutes(page, captor);

  await page.goto('/');
  await page.getByRole('button', { name: 'Reject all' }).click();

  await expect.poll(() => captor.body).not.toBeNull();
  expect(captor.body?.eventType).toBe('denied');
  const cats = captor.body?.categories as Record<string, boolean>;
  expect(cats.statistics).toBe(false);
  expect(cats.marketing).toBe(false);
  expect(cats.necessary).toBe(true);
});

test('customise lets the user toggle individual categories before saving', async ({ page }) => {
  const captor: ConsentPostCaptor = { body: null };
  await setupRoutes(page, captor);

  await page.goto('/');
  await page.getByRole('button', { name: 'Customise' }).click();
  await expect(page.locator('.effectcmp-categories')).toBeVisible();

  // Toggle statistics on
  await page.getByRole('switch', { name: 'statistics cookies' }).click();

  await page.getByRole('button', { name: 'Save preferences' }).click();

  await expect.poll(() => captor.body).not.toBeNull();
  expect(captor.body?.eventType).toBe('changed');
  const cats = captor.body?.categories as Record<string, boolean>;
  expect(cats.statistics).toBe(true);
  expect(cats.marketing).toBe(false);
});

test('banner does not re-show on second visit with valid stored consent', async ({ page }) => {
  const captor: ConsentPostCaptor = { body: null };
  await setupRoutes(page, captor);

  await page.goto('/');
  await page.getByRole('button', { name: 'Accept all' }).click();
  await expect(page.locator('.effectcmp-card')).toBeHidden();

  // Second visit — same session, cookie should be there.
  await page.goto('/');
  await page.waitForTimeout(300);
  await expect(page.locator('.effectcmp-card')).toBeHidden();
});

test('renders the bar-bottom layout pinned to the viewport bottom', async ({ page }) => {
  const captor: ConsentPostCaptor = { body: null };
  await page.route('**/assets.effectcmp.com/config/*', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        config: { ...sampleConfig, layout: 'bar-bottom' },
        country: 'GB',
      }),
    });
  });
  await page.route('**/assets.effectcmp.com/consent', async (route) => {
    await route.fulfill({
      status: 202,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true }),
    });
  });

  await page.goto('/');
  const shell = page.locator('.effectcmp-shell');
  await expect(shell).toBeVisible();
  // Bar layout has the shell pinned with explicit bottom positioning.
  const styles = await shell.evaluate((node) => {
    const computed = window.getComputedStyle(node);
    return { bottom: computed.bottom, position: computed.position };
  });
  expect(styles.position).toBe('fixed');
  expect(styles.bottom).toBe('0px');
  void captor;
});

test('renders the corner layout with the compact card in bottom-right', async ({ page }) => {
  await page.route('**/assets.effectcmp.com/config/*', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        config: { ...sampleConfig, layout: 'corner' },
        country: 'GB',
      }),
    });
  });
  await page.route('**/assets.effectcmp.com/consent', async (route) => {
    await route.fulfill({ status: 202, body: '{}' });
  });

  await page.goto('/');
  const shell = page.locator('.effectcmp-shell');
  await expect(shell).toBeVisible();
  const styles = await shell.evaluate((node) => {
    const computed = window.getComputedStyle(node);
    return { bottom: computed.bottom, right: computed.right };
  });
  expect(styles.bottom).toBe('16px');
  expect(styles.right).toBe('16px');
});

test('releases blocked scripts in granted categories on accept-all', async ({ page }) => {
  const captor: ConsentPostCaptor = { body: null };
  await setupRoutes(page, captor);

  await page.goto('/test/e2e/fixtures/host-with-blocked-scripts.html');
  await page.getByRole('button', { name: 'Accept all' }).click();
  await page.waitForTimeout(150);

  const flags = await page.evaluate(() => ({
    statistics:
      (window as unknown as { __cmpFiredStatistics?: boolean }).__cmpFiredStatistics === true,
    marketing:
      (window as unknown as { __cmpFiredMarketing?: boolean }).__cmpFiredMarketing === true,
    preferences:
      (window as unknown as { __cmpFiredPreferences?: boolean }).__cmpFiredPreferences === true,
  }));

  expect(flags.statistics).toBe(true);
  expect(flags.marketing).toBe(true);
  expect(flags.preferences).toBe(true);
});

test('leaves denied-category scripts blocked when user rejects', async ({ page }) => {
  const captor: ConsentPostCaptor = { body: null };
  await setupRoutes(page, captor);

  await page.goto('/test/e2e/fixtures/host-with-blocked-scripts.html');
  await page.getByRole('button', { name: 'Reject all' }).click();
  await page.waitForTimeout(150);

  const flags = await page.evaluate(() => ({
    statistics:
      (window as unknown as { __cmpFiredStatistics?: boolean }).__cmpFiredStatistics === true,
    marketing:
      (window as unknown as { __cmpFiredMarketing?: boolean }).__cmpFiredMarketing === true,
  }));
  expect(flags.statistics).toBe(false);
  expect(flags.marketing).toBe(false);

  // The blocked tags should still be in the DOM with their original type.
  const stillBlocked = await page.locator('script[type="text/plain"][data-category]').count();
  expect(stillBlocked).toBe(3);
});

test('does nothing dangerous if /config returns 404', async ({ page }) => {
  await page.route('**/assets.effectcmp.com/config/*', async (route) => {
    await route.fulfill({ status: 404, body: 'not found' });
  });

  const errors: string[] = [];
  page.on('pageerror', (err) => errors.push(err.message));

  await page.goto('/');
  await page.waitForTimeout(300);

  expect(errors).toHaveLength(0);
  await expect(page.locator('.effectcmp-card')).toHaveCount(0);
});
