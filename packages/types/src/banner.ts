import type { GcmDefaults } from './gcm.js';

export type CookieCategory = 'necessary' | 'preferences' | 'statistics' | 'marketing';

export type BannerLayout = 'modal' | 'bar-top' | 'bar-bottom' | 'corner';
export type BannerGranularity = 'basic' | 'categorised';

export interface BannerCopy {
  readonly title: string;
  readonly body: string;
  readonly acceptAllLabel: string;
  readonly rejectAllLabel: string;
  readonly customiseLabel: string;
  readonly savePreferencesLabel: string;
  readonly categoryDescriptions: Readonly<Record<CookieCategory, string>>;
}

export interface BannerColors {
  readonly background: string;
  readonly text: string;
  readonly primary: string;
  readonly primaryText: string;
  readonly secondary: string;
  readonly secondaryText: string;
}

export interface GeoRules {
  readonly mode: 'always' | 'eu_uk_only' | 'whitelist' | 'blacklist';
  readonly countries?: ReadonlyArray<string>;
}

export interface BehaviourRules {
  readonly rePromptAfterDays: number;
  readonly honourGpc: boolean;
  readonly autoDenyOnDismiss: boolean;
  readonly crossSubdomainConsent: boolean;
}

export interface BannerConfig {
  readonly domainPublicKey: string;
  readonly version: number;
  // Increments only when a publish materially changes how tracking runs —
  // GCM defaults, geo rules, GPC / auto-deny / cross-subdomain flags, or the
  // locale set. cmp.js stores this in the consent cookie; if the served
  // config's materialVersion is ahead, the visitor sees the banner again.
  readonly materialVersion: number;
  readonly localeDefault: string;
  readonly localesEnabled: ReadonlyArray<string>;
  readonly layout: BannerLayout;
  readonly granularity: BannerGranularity;
  readonly colors: BannerColors;
  readonly copy: Readonly<Record<string, BannerCopy>>;
  readonly geoRules: GeoRules;
  readonly gcmDefaults: GcmDefaults;
  readonly behaviour: BehaviourRules;
  readonly publishedAt: string;
  readonly signature: string;
}
