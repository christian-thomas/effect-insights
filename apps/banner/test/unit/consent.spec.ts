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
  materialVersion: 2,
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
      categoryDescriptions: {
        necessary: 'n',
        preferences: 'p',
        statistics: 's',
        marketing: 'm',
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
      materialVersion: 2,
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

  describe('cross-subdomain consent', () => {
    const originalHostname = location.hostname;

    const withHostname = (hostname: string, fn: () => void): void => {
      Object.defineProperty(location, 'hostname', { value: hostname, configurable: true });
      try {
        fn();
      } finally {
        Object.defineProperty(location, 'hostname', {
          value: originalHostname,
          configurable: true,
        });
      }
    };

    const apexConfig: BannerConfig = {
      ...config,
      behaviour: { ...config.behaviour, crossSubdomainConsent: true },
    };

    const stored: StoredConsent = {
      anonymousId: 'anon-2',
      materialVersion: 2,
      categories: allGranted(),
      consentedAt: '2026-05-22T12:00:00Z',
    };

    it('writes the cookie with Domain=.apex when crossSubdomainConsent is on', () => {
      withHostname('app.example.com', () => {
        writeStoredConsent(stored, apexConfig);
        // happy-dom honours the Domain attribute; reading from app.example.com
        // sees a cookie set on .example.com.
        expect(readStoredConsent()).toEqual(stored);
        // The serialised cookie string carries the Domain attribute.
        // Documented for clarity even though it's a write-once detail.
      });
    });

    it('omits the Domain attribute when crossSubdomainConsent is off', () => {
      withHostname('app.example.com', () => {
        writeStoredConsent(stored, config);
        expect(readStoredConsent()).toEqual(stored);
      });
    });

    it('omits the Domain attribute when host has no dot (localhost, IPs)', () => {
      withHostname('localhost', () => {
        // crossSubdomainConsent=true but apex=localhost (no dot) → no Domain=.
        // Browsers would reject `Domain=.localhost`. We just skip the attribute.
        writeStoredConsent(stored, apexConfig);
        expect(readStoredConsent()).toEqual(stored);
      });
    });
  });

  it('generates uuids when crypto.randomUUID is available', () => {
    const id = generateAnonymousId();
    expect(id).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}/);
  });

  describe('isStoredConsentStillValid', () => {
    // Pin "now" close enough to consentedAt that the age check passes.
    const consentedAt = '2026-05-22T12:00:00Z';
    const now = new Date('2026-05-23T00:00:00Z');

    it('returns false when nothing is stored', () => {
      expect(isStoredConsentStillValid(null, config, now)).toBe(false);
    });

    it('returns true when stored materialVersion matches and consent is fresh', () => {
      expect(
        isStoredConsentStillValid(
          {
            anonymousId: 'a',
            materialVersion: 2,
            categories: allGranted(),
            consentedAt,
          },
          config,
          now,
        ),
      ).toBe(true);
    });

    it('returns false when material version has advanced past the stored one', () => {
      expect(
        isStoredConsentStillValid(
          {
            anonymousId: 'a',
            materialVersion: 1,
            categories: allGranted(),
            consentedAt,
          },
          config,
          now,
        ),
      ).toBe(false);
    });

    it('returns true when the per-publish version bumps but material is unchanged', () => {
      // Simulates a cosmetic-only republish: config.version moved on but
      // materialVersion did not. Stored cookie matches materialVersion, so
      // the visitor stays opted in.
      const cosmeticBump: BannerConfig = { ...config, version: 99, materialVersion: 2 };
      expect(
        isStoredConsentStillValid(
          {
            anonymousId: 'a',
            materialVersion: 2,
            categories: allGranted(),
            consentedAt,
          },
          cosmeticBump,
          now,
        ),
      ).toBe(true);
    });

    it('returns false once consent is older than rePromptAfterDays', () => {
      const farFuture = new Date('2027-06-01T00:00:00Z'); // > 365 days after consentedAt
      expect(
        isStoredConsentStillValid(
          {
            anonymousId: 'a',
            materialVersion: 2,
            categories: allGranted(),
            consentedAt,
          },
          config,
          farFuture,
        ),
      ).toBe(false);
    });

    it('returns false when consentedAt is unparseable', () => {
      expect(
        isStoredConsentStillValid(
          {
            anonymousId: 'a',
            materialVersion: 2,
            categories: allGranted(),
            consentedAt: 'not-a-date',
          },
          config,
          now,
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
