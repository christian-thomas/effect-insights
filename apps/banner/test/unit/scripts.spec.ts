import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { unblockScripts, watchForBlockedScripts } from '../../src/scripts';
import type { ConsentCategories } from '../../src/types';

const allowAll: ConsentCategories = {
  necessary: true,
  preferences: true,
  statistics: true,
  marketing: true,
};

const denyMarketing: ConsentCategories = {
  necessary: true,
  preferences: true,
  statistics: true,
  marketing: false,
};

const resetDom = (): void => {
  document.documentElement.innerHTML = '<head></head><body></body>';
};

describe('scripts', () => {
  beforeEach(resetDom);
  afterEach(resetDom);

  it('does nothing when there are no blocked scripts', () => {
    expect(unblockScripts(allowAll)).toBe(0);
  });

  it('re-injects external blocked scripts whose category is granted', () => {
    document.body.innerHTML = `
      <script type="text/plain" data-category="statistics" src="https://example.test/ga.js" async></script>
    `;

    const released = unblockScripts(allowAll);

    expect(released).toBe(1);
    const live = document.querySelector('script[src="https://example.test/ga.js"]');
    expect(live).not.toBeNull();
    expect(live?.getAttribute('type')).not.toBe('text/plain');
    expect(live?.hasAttribute('data-category')).toBe(false);
    expect(live?.hasAttribute('async')).toBe(true);
  });

  it('preserves inline script body when releasing', () => {
    document.body.innerHTML = `
      <script type="text/plain" data-category="marketing">window.__test = 'released';</script>
    `;

    unblockScripts(allowAll);

    const released = Array.from(document.querySelectorAll('script')).find(
      (script) =>
        script.getAttribute('type') !== 'text/plain' && script.textContent?.includes('__test'),
    );
    expect(released).toBeDefined();
    expect(released?.textContent).toContain('__test');
  });

  it('skips scripts whose category was denied', () => {
    document.body.innerHTML = `
      <script type="text/plain" data-category="statistics" src="https://example.test/ga.js"></script>
      <script type="text/plain" data-category="marketing" src="https://example.test/ads.js"></script>
    `;

    const released = unblockScripts(denyMarketing);

    expect(released).toBe(1);
    // marketing script stays blocked (still type="text/plain", still has data-category)
    const ads = document.querySelector('script[src="https://example.test/ads.js"]');
    expect(ads?.getAttribute('type')).toBe('text/plain');
    expect(ads?.getAttribute('data-category')).toBe('marketing');
  });

  it('does not release the same script twice on repeated calls', () => {
    document.body.innerHTML = `
      <script type="text/plain" data-category="statistics" src="https://example.test/ga.js"></script>
    `;

    expect(unblockScripts(allowAll)).toBe(1);
    expect(unblockScripts(allowAll)).toBe(0);
  });

  it('ignores scripts with no data-category', () => {
    document.body.innerHTML = `
      <script type="text/plain">window.__should_stay_blocked = true;</script>
    `;

    expect(unblockScripts(allowAll)).toBe(0);
  });

  it('mutation observer unblocks late-arriving scripts', async () => {
    const stop = watchForBlockedScripts(() => allowAll);

    const late = document.createElement('script');
    late.setAttribute('type', 'text/plain');
    late.setAttribute('data-category', 'statistics');
    late.setAttribute('src', 'https://example.test/late.js');
    document.body.appendChild(late);

    await new Promise((resolve) => setTimeout(resolve, 10));

    const live = document.querySelector('script[src="https://example.test/late.js"]');
    expect(live).not.toBeNull();
    expect(live?.getAttribute('type')).not.toBe('text/plain');
    stop();
  });

  it('propagates the host-page CSP nonce to released scripts', () => {
    document.body.innerHTML = `
      <script type="text/plain" data-category="statistics" src="https://example.test/ga.js"></script>
    `;

    unblockScripts(allowAll, 'host-nonce-123');

    const live = document.querySelector<HTMLScriptElement>(
      'script[src="https://example.test/ga.js"]',
    );
    expect(live).not.toBeNull();
    expect(live?.getAttribute('nonce')).toBe('host-nonce-123');
    expect(live?.nonce).toBe('host-nonce-123');
  });

  it('preserves the original script nonce property over the host-page nonce', () => {
    const original = document.createElement('script');
    original.setAttribute('type', 'text/plain');
    original.setAttribute('data-category', 'statistics');
    original.setAttribute('src', 'https://example.test/ga.js');
    (original as HTMLScriptElement & { nonce?: string }).nonce = 'original-nonce';
    document.body.appendChild(original);

    unblockScripts(allowAll, 'host-fallback-nonce');

    const live = document.querySelector<HTMLScriptElement>(
      'script[src="https://example.test/ga.js"]',
    );
    expect(live?.getAttribute('nonce')).toBe('original-nonce');
  });

  it('does not add a nonce when none is configured', () => {
    document.body.innerHTML = `
      <script type="text/plain" data-category="statistics" src="https://example.test/ga.js"></script>
    `;

    unblockScripts(allowAll, null);

    const live = document.querySelector<HTMLScriptElement>(
      'script[src="https://example.test/ga.js"]',
    );
    expect(live?.hasAttribute('nonce')).toBe(false);
  });
});
