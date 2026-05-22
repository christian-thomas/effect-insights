import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { installDefaults, sendUpdate } from '../../src/gcm';
import type { GcmDefaults } from '../../src/types';

const denyAll: GcmDefaults = {
  ad_storage: 'denied',
  analytics_storage: 'denied',
  ad_user_data: 'denied',
  ad_personalization: 'denied',
  functionality_storage: 'granted',
  personalization_storage: 'denied',
  security_storage: 'granted',
};

interface DataLayerWindow {
  dataLayer?: unknown[];
}

const layer = (): unknown[] =>
  ((window as unknown as DataLayerWindow).dataLayer ?? []) as unknown[];

describe('gcm', () => {
  beforeEach(() => {
    (window as unknown as DataLayerWindow).dataLayer = undefined;
  });

  afterEach(() => {
    (window as unknown as DataLayerWindow).dataLayer = undefined;
  });

  it('pushes consent default with the given signals', () => {
    installDefaults(denyAll);
    expect(layer()).toEqual([['consent', 'default', denyAll]]);
  });

  it('creates window.dataLayer if it does not exist', () => {
    expect((window as unknown as DataLayerWindow).dataLayer).toBeUndefined();
    installDefaults(denyAll);
    expect(Array.isArray((window as unknown as DataLayerWindow).dataLayer)).toBe(true);
  });

  it('appends to an existing window.dataLayer', () => {
    (window as unknown as DataLayerWindow).dataLayer = [['existing', 'event']];
    installDefaults(denyAll);
    expect(layer()).toHaveLength(2);
    expect((layer()[0] as unknown[])[0]).toBe('existing');
  });

  it('translates user categories into GCM update signals on sendUpdate', () => {
    sendUpdate({
      necessary: true,
      preferences: true,
      statistics: true,
      marketing: false,
    });

    expect(layer()).toEqual([
      [
        'consent',
        'update',
        {
          analytics_storage: 'granted',
          ad_storage: 'denied',
          ad_user_data: 'denied',
          ad_personalization: 'denied',
          personalization_storage: 'granted',
        },
      ],
    ]);
  });
});
