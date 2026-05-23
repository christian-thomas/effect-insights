import type { BannerConfig, ConsentCategories, StoredConsent } from './types';

const COOKIE_NAME = '_effectcmp';
const SECONDS_PER_DAY = 86_400;

const isBrowser = (): boolean => typeof document !== 'undefined';

export const readStoredConsent = (): StoredConsent | null => {
  if (!isBrowser()) return null;
  const match = document.cookie.match(new RegExp(`(?:^|;\\s*)${COOKIE_NAME}=([^;]+)`));
  if (!match || !match[1]) return null;
  try {
    return JSON.parse(decodeURIComponent(match[1])) as StoredConsent;
  } catch {
    return null;
  }
};

export const writeStoredConsent = (consent: StoredConsent, config: BannerConfig): void => {
  if (!isBrowser()) return;
  const maxAge = Math.max(1, config.behaviour.rePromptAfterDays) * SECONDS_PER_DAY;
  const value = encodeURIComponent(JSON.stringify(consent));
  document.cookie = `${COOKIE_NAME}=${value}; Max-Age=${maxAge}; Path=/; SameSite=Lax`;
};

export const generateAnonymousId = (): string => {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
    return crypto.randomUUID();
  }
  // Fallback for ancient browsers — not cryptographically strong but acceptable
  // since this is an anonymous session identifier, not a security token.
  return 'anon_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
};

export const allDenied = (): ConsentCategories => ({
  necessary: true,
  preferences: false,
  statistics: false,
  marketing: false,
});

export const allGranted = (): ConsentCategories => ({
  necessary: true,
  preferences: true,
  statistics: true,
  marketing: true,
});

export const isStoredConsentStillValid = (
  stored: StoredConsent | null,
  config: BannerConfig,
): boolean => {
  if (stored === null) return false;
  // Stale once the banner version bumps past what the visitor saw last time.
  // iteration 5 will refine this with material-vs-cosmetic change detection.
  return stored.bannerConfigVersion >= config.version;
};

export interface ConsentPostBody {
  id: string;
  domainPublicKey: string;
  bannerConfigVersion: number;
  anonymousId: string;
  categories: ConsentCategories;
  gcmSignals: Record<string, 'granted' | 'denied'>;
  countryCode: string | null;
  userAgentHash: string;
  ipHash: string;
  eventType: 'granted' | 'denied' | 'changed' | 'withdrawn';
  occurredAt: string;
  bannerTextHash: string;
}

export const buildConsentEvent = (params: {
  anonymousId: string;
  config: BannerConfig;
  categories: ConsentCategories;
  country: string | null;
  eventType: ConsentPostBody['eventType'];
}): ConsentPostBody => {
  const { anonymousId, config, categories, country, eventType } = params;
  const stats = categories.statistics ? 'granted' : 'denied';
  const marketing = categories.marketing ? 'granted' : 'denied';
  const prefs = categories.preferences ? 'granted' : 'denied';

  return {
    id: generateAnonymousId(),
    domainPublicKey: config.domainPublicKey,
    bannerConfigVersion: config.version,
    anonymousId,
    categories,
    gcmSignals: {
      analytics_storage: stats,
      ad_storage: marketing,
      ad_user_data: marketing,
      ad_personalization: marketing,
      personalization_storage: prefs,
      functionality_storage: config.gcmDefaults.functionality_storage,
      security_storage: config.gcmDefaults.security_storage,
    },
    countryCode: country,
    // iteration 5 will hash the UA + IP server-side at the edge; for v0 we
    // pass placeholders that satisfy the zod schema.
    userAgentHash: 'client_unhashed',
    ipHash: 'client_unhashed',
    eventType,
    occurredAt: new Date().toISOString(),
    bannerTextHash: `v${config.version}`,
  };
};

export const postConsentEvent = async (baseUrl: string, body: ConsentPostBody): Promise<void> => {
  await fetch(`${baseUrl}/consent`, {
    method: 'POST',
    credentials: 'omit',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
};
