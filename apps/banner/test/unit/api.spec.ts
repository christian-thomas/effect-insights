import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
  installPublicApi,
  type ConsentChangeEvent,
  type CmpApi,
} from '../../src/api';
import type { BannerConfig, ConsentCategories, StoredConsent } from '../../src/types';

const config: BannerConfig = {
  domainPublicKey: 'pk_test_api',
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
  copy: {
    en: {
      title: 't',
      body: 'b',
      acceptAllLabel: 'a',
      rejectAllLabel: 'r',
      customiseLabel: 'c',
      savePreferencesLabel: 's',
      categoryDescriptions: { necessary: 'n', preferences: 'p', statistics: 's', marketing: 'm' },
    },
  },
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
  publishedAt: '2026-01-01T00:00:00Z',
  signature: 'sig',
};

const grantedCategories: ConsentCategories = {
  necessary: true,
  preferences: true,
  statistics: true,
  marketing: true,
};

const deniedCategories: ConsentCategories = {
  necessary: true,
  preferences: false,
  statistics: false,
  marketing: false,
};

const buildStored = (categories: ConsentCategories): StoredConsent => ({
  anonymousId: 'anon_test',
  materialVersion: 1,
  categories,
  consentedAt: new Date().toISOString(),
});

beforeEach(() => {
  // Each test starts with a clean window.cmp slot.
  delete (window as Window & { cmp?: CmpApi }).cmp;
});

describe('window.cmp public API', () => {
  it('installs eagerly on window', () => {
    installPublicApi();
    expect(window.cmp).toBeDefined();
    expect(window.cmp?.isReady()).toBe(false);
  });

  it('exposes getConsent as null until consent is recorded', () => {
    const host = installPublicApi();
    let stored: StoredConsent | null = null;
    host.attachBindings({
      config,
      readStoredConsent: () => stored,
      openBanner: () => {},
      recordConsent: () => Promise.resolve(),
    });

    expect(window.cmp?.getConsent()).toBeNull();

    stored = buildStored(grantedCategories);
    expect(window.cmp?.getConsent()).toEqual(grantedCategories);
  });

  it('flips isReady once bindings are attached', () => {
    const host = installPublicApi();
    expect(window.cmp?.isReady()).toBe(false);

    host.attachBindings({
      config,
      readStoredConsent: () => null,
      openBanner: () => {},
      recordConsent: () => Promise.resolve(),
    });

    expect(window.cmp?.isReady()).toBe(true);
  });

  it('queues pre-ready actions and replays them on attach', async () => {
    const host = installPublicApi();
    const openBanner = vi.fn();
    const recordConsent = vi.fn().mockResolvedValue(undefined);

    // Caller hits cmp.openBanner() before cmp.js' boot finishes.
    window.cmp?.openBanner();
    window.cmp?.acceptAll();

    expect(openBanner).not.toHaveBeenCalled();
    expect(recordConsent).not.toHaveBeenCalled();

    host.attachBindings({
      config,
      readStoredConsent: () => null,
      openBanner,
      recordConsent,
    });

    expect(openBanner).toHaveBeenCalledTimes(1);
    expect(recordConsent).toHaveBeenCalledWith({
      categories: grantedCategories,
      eventType: 'granted',
    });
  });

  it('fires ready callbacks immediately on attach and via microtask after', async () => {
    const host = installPublicApi();
    const earlyCallback = vi.fn();
    const lateCallback = vi.fn();

    window.cmp?.ready(earlyCallback);
    expect(earlyCallback).not.toHaveBeenCalled();

    host.attachBindings({
      config,
      readStoredConsent: () => null,
      openBanner: () => {},
      recordConsent: () => Promise.resolve(),
    });

    await new Promise<void>((resolve) => queueMicrotask(resolve));
    expect(earlyCallback).toHaveBeenCalledTimes(1);

    window.cmp?.ready(lateCallback);
    expect(lateCallback).not.toHaveBeenCalled();
    await new Promise<void>((resolve) => queueMicrotask(resolve));
    expect(lateCallback).toHaveBeenCalledTimes(1);
  });

  it('rejectAll records denied categories', () => {
    const host = installPublicApi();
    const recordConsent = vi.fn().mockResolvedValue(undefined);
    host.attachBindings({
      config,
      readStoredConsent: () => null,
      openBanner: () => {},
      recordConsent,
    });

    window.cmp?.rejectAll();
    expect(recordConsent).toHaveBeenCalledWith({
      categories: deniedCategories,
      eventType: 'denied',
    });
  });

  it('setConsent normalises missing keys to false and forces necessary true', () => {
    const host = installPublicApi();
    const recordConsent = vi.fn().mockResolvedValue(undefined);
    host.attachBindings({
      config,
      readStoredConsent: () => null,
      openBanner: () => {},
      recordConsent,
    });

    window.cmp?.setConsent({ statistics: true });
    expect(recordConsent).toHaveBeenCalledWith({
      categories: {
        necessary: true,
        preferences: false,
        statistics: true,
        marketing: false,
      },
      eventType: 'changed',
    });
  });

  it('withdraw records a withdrawn event AND re-opens the banner', () => {
    const host = installPublicApi();
    const recordConsent = vi.fn().mockResolvedValue(undefined);
    const openBanner = vi.fn();
    host.attachBindings({
      config,
      readStoredConsent: () => buildStored(grantedCategories),
      openBanner,
      recordConsent,
    });

    window.cmp?.withdraw();
    expect(recordConsent).toHaveBeenCalledWith({
      categories: deniedCategories,
      eventType: 'withdrawn',
    });
    expect(openBanner).toHaveBeenCalledTimes(1);
  });

  it('notifies subscribers on consent change + dispatches a DOM event', () => {
    const host = installPublicApi();
    host.attachBindings({
      config,
      readStoredConsent: () => buildStored(grantedCategories),
      openBanner: () => {},
      recordConsent: () => Promise.resolve(),
    });

    const listener = vi.fn();
    const domListener = vi.fn();
    const unsubscribe = window.cmp?.onConsentChange(listener);
    document.addEventListener('cmp:consent-change', domListener);

    const event: ConsentChangeEvent = {
      type: 'granted',
      categories: grantedCategories,
      consent: buildStored(grantedCategories),
    };
    host.notifyConsentChange(event);

    expect(listener).toHaveBeenCalledWith(event);
    expect(domListener).toHaveBeenCalledTimes(1);

    unsubscribe?.();
    host.notifyConsentChange(event);
    expect(listener).toHaveBeenCalledTimes(1);

    document.removeEventListener('cmp:consent-change', domListener);
  });

  it('one subscriber throwing does not stop the others', () => {
    const host = installPublicApi();
    host.attachBindings({
      config,
      readStoredConsent: () => null,
      openBanner: () => {},
      recordConsent: () => Promise.resolve(),
    });

    const bad = vi.fn(() => {
      throw new Error('boom');
    });
    const good = vi.fn();
    window.cmp?.onConsentChange(bad);
    window.cmp?.onConsentChange(good);

    host.notifyConsentChange({
      type: 'granted',
      categories: grantedCategories,
      consent: buildStored(grantedCategories),
    });

    expect(bad).toHaveBeenCalled();
    expect(good).toHaveBeenCalled();
  });
});
