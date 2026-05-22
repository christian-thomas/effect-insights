// Wire-format shape of the banner config that Laravel publishes to CF KV
// and the edge worker returns from /config/:publicKey. Must match the PHP
// builder (app/Support/BannerConfigPayloadBuilder.php) and the canonical
// type in packages/types/src/banner.ts.

export type CookieCategory = 'necessary' | 'preferences' | 'statistics' | 'marketing';

export type BannerLayout = 'modal' | 'bar-top' | 'bar-bottom' | 'corner';

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
  localeDefault: string;
  localesEnabled: string[];
  layout: BannerLayout;
  granularity: 'basic' | 'categorised';
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
  bannerConfigVersion: number;
  categories: ConsentCategories;
  consentedAt: string;
}
