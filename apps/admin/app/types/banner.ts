import type { BannerDraftInput } from '@effect-cmp/types/schemas/banner';

export interface BannerConfig {
  id: string;
  domainId: string;
  version: number;
  isDraft: boolean;
  isPublished: boolean;
  layout: BannerDraftInput['layout'];
  localeDefault: string;
  localesEnabled: string[];
  colors: BannerDraftInput['colors'];
  copy: BannerDraftInput['copy'];
  gcmDefaults: BannerDraftInput['gcm_defaults'];
  geoRules: BannerDraftInput['geo_rules'];
  behaviour: BannerDraftInput['behaviour'];
  publishedAt: string | null;
  createdAt: string;
  updatedAt: string;
}
