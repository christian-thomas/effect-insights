import type { ConsentCategories, GcmDefaults } from './types';

/**
 * Google Consent Mode v2 — push `consent` events to window.dataLayer in the
 * gtag wire format. Defaults MUST fire before any gtag.js / GA / Ads script
 * loads on the host page, so callers should invoke installDefaults() before
 * doing anything that yields (await, requestAnimationFrame, DOM mutations).
 *
 * The host page is responsible for placing the embed snippet at the top of
 * <head>, before any analytics tags.
 */

interface DataLayerWindow {
  dataLayer?: unknown[];
}

const ensureDataLayer = (): unknown[] => {
  const target = window as unknown as DataLayerWindow;
  if (!Array.isArray(target.dataLayer)) {
    target.dataLayer = [];
  }
  return target.dataLayer;
};

const push = (...args: unknown[]): void => {
  ensureDataLayer().push(args);
};

export const installDefaults = (defaults: GcmDefaults): void => {
  push('consent', 'default', { ...defaults });
};

const categoriesToSignals = (categories: ConsentCategories): Partial<GcmDefaults> => {
  const stats = categories.statistics ? 'granted' : 'denied';
  const marketing = categories.marketing ? 'granted' : 'denied';
  const prefs = categories.preferences ? 'granted' : 'denied';

  return {
    analytics_storage: stats,
    ad_storage: marketing,
    ad_user_data: marketing,
    ad_personalization: marketing,
    personalization_storage: prefs,
    // functionality_storage + security_storage stay at defaults — they're
    // not user-toggleable in v0 (necessary category covers them implicitly).
  };
};

export const sendUpdate = (categories: ConsentCategories): void => {
  push('consent', 'update', categoriesToSignals(categories));
};
