import { beforeEach, describe, expect, it } from 'vitest';
import {
  allDenied,
  allGranted,
  buildConsentEvent,
  generateAnonymousId,
  isStoredConsentStillValid,
  readStoredConsent,
  writeStoredConsent,
} from '../../src/consent';
import type { BannerConfig, StoredConsent } from '../../src/types';

const config: BannerConfig = {
  domainPublicKey: 'pk_test_abc',
  version: 3,
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
      title: 't', body: 'b',
      acceptAllLabel: 'a', rejectAllLabel: 'r',
      customiseLabel: 'c', savePreferencesLabel: 's',
      categoryDescriptions: {
        necessary: 'n', preferences: 'p', statistics: 's', marketing: 'm',
      },
    },
  },
  geoRules: { mode: 'always' },
  gcmDefaults: {
    ad_storage: 'denied', analytics_storage: 'denied',
    ad_user_data: 'denied', ad_personalization: 'denied',
    functionality_storage: 'granted', personalization_storage: 'denied',
    security_storage: 'granted',
  },
  behaviour: {
    rePromptAfterDays: 365, honourGpc: true,
    autoDenyOnDismiss: false, crossSubdomainConsent: false,
  },
  publishedAt: '2026-05-22T00:00:00Z',
  signature: 'unsigned',
};

const clearCookies = (): void => {
  document.cookie.split(';').forEach((cookie) => {
    const name = cookie.split('=')[0]?.trim();
    if (name) {
      document.cookie = `${name}=; Max-Age=0; Path=/`;
    }
  });
};

describe('consent', () => {
  beforeEach(() => {
    clearCookies();
  });

  it('returns null when no cookie is set', () => {
    expect(readStoredConsent()).toBeNull();
  });

  it('round-trips a stored consent through document.cookie', () => {
    const stored: StoredConsent = {
      anonymousId: 'anon-1',
      bannerConfigVersion: 3,
      categories: allGranted(),
      consentedAt: '2026-05-22T12:00:00Z',
    };

    writeStoredConsent(stored, config);
    expect(readStoredConsent()).toEqual(stored);
  });

  it('returns null for garbled cookie values', () => {
    document.cookie = '_effectcmp=not-json; Path=/';
    expect(readStoredConsent()).toBeNull();
  });

  it('generates uuids when crypto.randomUUID is available', () => {
    const id = generateAnonymousId();
    expect(id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}/);
  });

  describe('isStoredConsentStillValid', () => {
    it('returns false when nothing is stored', () => {
      expect(isStoredConsentStillValid(null, config)).toBe(false);
    });

    it('returns true when stored version matches', () => {
      expect(
        isStoredConsentStillValid(
          {
            anonymousId: 'a',
            bannerConfigVersion: 3,
            categories: allGranted(),
            consentedAt: '2026-05-22T12:00:00Z',
          },
          config,
        ),
      ).toBe(true);
    });

    it('returns false when banner version has advanced', () => {
      expect(
        isStoredConsentStillValid(
          {
            anonymousId: 'a',
            bannerConfigVersion: 2,
            categories: allGranted(),
            consentedAt: '2026-05-22T12:00:00Z',
          },
          config,
        ),
      ).toBe(false);
    });
  });

  describe('buildConsentEvent', () => {
    it('maps granted categories to allowed GCM signals', () => {
      const event = buildConsentEvent({
        anonymousId: 'anon-x',
        config,
        categories: allGranted(),
        country: 'GB',
        eventType: 'granted',
      });

      expect(event.domainPublicKey).toBe('pk_test_abc');
      expect(event.bannerConfigVersion).toBe(3);
      expect(event.eventType).toBe('granted');
      expect(event.countryCode).toBe('GB');
      expect(event.gcmSignals.analytics_storage).toBe('granted');
      expect(event.gcmSignals.ad_storage).toBe('granted');
      expect(event.gcmSignals.functionality_storage).toBe('granted');
    });

    it('maps a fully denied selection to denied signals', () => {
      const event = buildConsentEvent({
        anonymousId: 'anon-x',
        config,
        categories: allDenied(),
        country: null,
        eventType: 'denied',
      });

      expect(event.gcmSignals.analytics_storage).toBe('denied');
      expect(event.gcmSignals.ad_storage).toBe('denied');
      // functionality + security stay at the configured defaults regardless
      expect(event.gcmSignals.functionality_storage).toBe('granted');
      expect(event.gcmSignals.security_storage).toBe('granted');
    });
  });
});
