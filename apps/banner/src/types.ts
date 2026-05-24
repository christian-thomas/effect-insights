// Wire-format shape of the banner config that Laravel publishes to CF KV
// and the edge worker returns from /config/:publicKey. Must match the PHP
// builder (app/Support/BannerConfigPayloadBuilder.php) and the canonical
// type in packages/types/src/banner.ts.

export type CookieCategory = 'necessary' | 'preferences' | 'statistics' | 'marketing';

export type BannerLayout = 'modal' | 'bar-top' | 'bar-bottom' | 'corner';

export type BannerGranularity = 'basic' | 'categorised';

export type GcmSignal = 'granted' | 'denied';

export interface BannerColors {
  background: string;
  text: string;
  primary: string;
  primaryText: string;
  secondary: string;
  secondaryText: string;
}

export interface CategoryDescriptions {
  necessary: string;
  preferences: string;
  statistics: string;
  marketing: string;
}

export interface BannerCopy {
  title: string;
  body: string;
  acceptAllLabel: string;
  rejectAllLabel: string;
  customiseLabel: string;
  savePreferencesLabel: string;
  categoryDescriptions: CategoryDescriptions;
}

export interface GcmDefaults {
  ad_storage: GcmSignal;
  analytics_storage: GcmSignal;
  ad_user_data: GcmSignal;
  ad_personalization: GcmSignal;
  functionality_storage: GcmSignal;
  personalization_storage: GcmSignal;
  security_storage: GcmSignal;
}

export interface GeoRules {
  mode: 'always' | 'eu_uk_only' | 'whitelist' | 'blacklist';
  countries?: string[];
}

export interface BannerBehaviour {
  rePromptAfterDays: number;
  honourGpc: boolean;
  autoDenyOnDismiss: boolean;
  crossSubdomainConsent: boolean;
}

export interface BannerConfig {
  domainPublicKey: string;
  version: number;
  // See packages/types/src/banner.ts — the re-prompt key. Only bumps on
  // material change; cosmetic re-publishes leave it alone.
  materialVersion: number;
  localeDefault: string;
  localesEnabled: string[];
  layout: BannerLayout;
  granularity: BannerGranularity;
  colors: BannerColors;
  copy: Record<string, BannerCopy>;
  geoRules: GeoRules;
  gcmDefaults: GcmDefaults;
  behaviour: BannerBehaviour;
  publishedAt: string;
  signature: string;
}

export interface ConfigResponse {
  config: BannerConfig;
  country: string | null;
}

export type ConsentCategories = Record<CookieCategory, boolean>;

export interface StoredConsent {
  anonymousId: string;
  // The materialVersion of the banner config at consent time. Used on the
  // next page load to decide whether to re-prompt (current > stored = bump
  // happened since the visitor consented).
  materialVersion: number;
  categories: ConsentCategories;
  // ISO 8601 instant. Used both for the audit log and for the
  // rePromptAfterDays freshness check.
  consentedAt: string;
}
