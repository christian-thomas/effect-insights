import type { BannerConfig, ConsentCategories, StoredConsent } from './types';

const COOKIE_NAME = '_effectinsight';
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

/**
 * Trivial apex extraction — last two dot-segments of the hostname. Mirrors
 * the PHP `Domain::extractApex` exactly so client + server agree. Doesn't
 * handle the public suffix list (co.uk, gov.au — visitors on those would
 * incorrectly get a `Domain=co.uk` cookie which the browser silently
 * refuses to set). When that bites a client we'll bring in tldts.
 */
const apexDomainOf = (hostname: string): string => {
  const parts = hostname.toLowerCase().split('.');
  if (parts.length <= 2) {
    return parts.join('.');
  }
  return parts.slice(-2).join('.');
};

const buildCookieDomainAttribute = (config: BannerConfig): string => {
  if (!config.behaviour.crossSubdomainConsent) return '';
  if (typeof location === 'undefined') return '';
  const apex = apexDomainOf(location.hostname);
  // No Domain= attribute for IP addresses or localhost — browsers reject
  // those. Cheap detection: an apex with no dot is one of those cases.
  if (!apex.includes('.')) return '';
  return `; Domain=.${apex}`;
};

export const writeStoredConsent = (consent: StoredConsent, config: BannerConfig): void => {
  if (!isBrowser()) return;
  const maxAge = Math.max(1, config.behaviour.rePromptAfterDays) * SECONDS_PER_DAY;
  const value = encodeURIComponent(JSON.stringify(consent));
  const domainAttr = buildCookieDomainAttribute(config);
  document.cookie = `${COOKIE_NAME}=${value}; Max-Age=${maxAge}; Path=/; SameSite=Lax${domainAttr}`;
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

const MS_PER_DAY = 86_400_000;

export const isStoredConsentStillValid = (
  stored: StoredConsent | null,
  config: BannerConfig,
  now: Date = new Date(),
): boolean => {
  if (stored === null) return false;

  // Material bump since last consent → re-prompt. Cosmetic-only republishes
  // (colours, copy, layout, locale-default) leave materialVersion alone, so
  // the visitor isn't asked again for a re-skin.
  if (stored.materialVersion < config.materialVersion) {
    return false;
  }

  // ICO guidance: re-prompt at least once every 12 months. behaviour.
  // rePromptAfterDays is configurable per-domain but defaults to 365.
  const consentedAt = Date.parse(stored.consentedAt);
  if (Number.isNaN(consentedAt)) {
    return false;
  }
  const ageDays = (now.getTime() - consentedAt) / MS_PER_DAY;
  if (ageDays >= config.behaviour.rePromptAfterDays) {
    return false;
  }

  return true;
};

export interface ConsentPostBody {
  id: string;
  domainPublicKey: string;
  // The per-publish version (NOT materialVersion) — this is the audit
  // identifier sent to Laravel so we can later look up the exact banner
  // text + categorisation the visitor saw at consent time.
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
  const response = await fetch(`${baseUrl}/consent`, {
    method: 'POST',
    credentials: 'omit',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
  // 4xx is a permanent shape/auth failure — retrying won't help, drop it.
  // 5xx is server-side, transient — let the caller decide (outbox queue).
  if (response.status >= 500) {
    throw new Error(`consent POST failed with ${response.status}`);
  }
};
