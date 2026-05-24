import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

/**
 * Axe-core baseline. Runs against the inline-config preview host
 * (`host-preview.html`) so this test doesn't need the edge worker
 * or any network mocking.
 *
 * Scoped to the banner root (`[role="dialog"]`) so that issues in the
 * host page itself (which is intentionally bare) don't show up as
 * cmp.js failures.
 */
test.describe('banner accessibility', () => {
  test('passes axe-core with no WCAG 2.1 A/AA violations', async ({ page }) => {
    await page.goto('/test/e2e/fixtures/host-preview.html');
    await page.locator('.tracewatch-card').waitFor({ state: 'visible' });

    const results = await new AxeBuilder({ page })
      .include('[role="dialog"]')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();

    if (results.violations.length > 0) {
      // Surface the rule + impact + node selectors so the test output
      // points the reader straight at the broken element.
      const formatted = results.violations
        .map((violation) =>
          [
            `[${violation.impact ?? 'unknown'}] ${violation.id}: ${violation.help}`,
            `  ${violation.helpUrl}`,
            ...violation.nodes.map((node) => `    ${node.target.join(' ')}`),
          ].join('\n'),
        )
        .join('\n');
      throw new Error(`axe-core found ${results.violations.length} violation(s):\n${formatted}`);
    }

    expect(results.violations).toEqual([]);
  });

  test('initial focus lands inside the dialog', async ({ page }) => {
    await page.goto('/test/e2e/fixtures/host-preview.html');
    await page.locator('.tracewatch-card').waitFor({ state: 'visible' });
    // queueMicrotask in renderBanner means focus arrives a tick after the
    // dialog mounts. Tiny wait covers it.
    await page.waitForFunction(() => {
      const active = document.activeElement;
      return active !== null && active.closest('[role="dialog"]') !== null;
    });
  });

  test('Tab cycles inside the dialog (focus trap)', async ({ page }) => {
    await page.goto('/test/e2e/fixtures/host-preview.html');
    await page.locator('.tracewatch-card').waitFor({ state: 'visible' });

    await page.waitForFunction(() => document.activeElement?.closest('[role="dialog"]') !== null);

    // Press Tab many more times than there are focusable elements; focus
    // must never leave the dialog. Sequential by design — each Tab depends
    // on the previous focus state.
    for (let i = 0; i < 12; i++) {
      // eslint-disable-next-line no-await-in-loop -- intentionally sequential: each Tab depends on previous focus state
      await page.keyboard.press('Tab');
      // eslint-disable-next-line no-await-in-loop -- ditto
      const stillInside = await page.evaluate(
        () => document.activeElement?.closest('[role="dialog"]') !== null,
      );
      expect(stillInside, `Tab #${i + 1} escaped the dialog`).toBe(true);
    }
  });

  test('Escape inside the customise pane returns to the simple view', async ({ page }) => {
    await page.goto('/test/e2e/fixtures/host-preview.html');
    await page.locator('.tracewatch-card').waitFor({ state: 'visible' });

    // Open the customise pane.
    const customise = page.getByRole('button', { name: 'Customise' });
    await customise.click();
    await expect(customise).toHaveAttribute('aria-expanded', 'true');

    // Esc collapses it.
    await page.keyboard.press('Escape');
    await expect(customise).toHaveAttribute('aria-expanded', 'false');
  });
});
