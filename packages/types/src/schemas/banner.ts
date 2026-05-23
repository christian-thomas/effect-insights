import { z } from 'zod';

export const layoutSchema = z.enum(['modal', 'bar-top', 'bar-bottom', 'corner']);
export type BannerLayout = z.infer<typeof layoutSchema>;

const hex = z.string().regex(/^#[0-9a-fA-F]{6}$/, 'Use a 6-digit hex colour, e.g. #2563eb');

const localeCode = z
  .string()
  .regex(/^[a-z]{2}(?:-[A-Z]{2})?$/, 'Locale must be an ISO 639-1 code (en, fr, de-CH)');

const countryCode = z
  .string()
  .regex(/^[A-Z]{2}$/, 'Country must be an ISO 3166-1 alpha-2 code (GB, US)');

export const bannerColorsSchema = z.object({
  background: hex,
  text: hex,
  primary: hex,
  primaryText: hex,
  secondary: hex,
  secondaryText: hex,
});
export type BannerColors = z.infer<typeof bannerColorsSchema>;

export const bannerCopySchema = z.object({
  title: z.string().min(1).max(120),
  body: z.string().min(1).max(600),
  acceptAllLabel: z.string().min(1).max(60),
  rejectAllLabel: z.string().min(1).max(60),
  customiseLabel: z.string().min(1).max(60),
  savePreferencesLabel: z.string().min(1).max(60),
  categoryDescriptions: z.object({
    necessary: z.string().min(1).max(300),
    preferences: z.string().min(1).max(300),
    statistics: z.string().min(1).max(300),
    marketing: z.string().min(1).max(300),
  }),
});
export type BannerCopy = z.infer<typeof bannerCopySchema>;

export const gcmSignalSchema = z.enum(['granted', 'denied']);

export const gcmDefaultsSchema = z.object({
  ad_storage: gcmSignalSchema,
  analytics_storage: gcmSignalSchema,
  ad_user_data: gcmSignalSchema,
  ad_personalization: gcmSignalSchema,
  functionality_storage: gcmSignalSchema,
  personalization_storage: gcmSignalSchema,
  security_storage: gcmSignalSchema,
});
export type GcmDefaults = z.infer<typeof gcmDefaultsSchema>;

export const geoRulesSchema = z.object({
  mode: z.enum(['always', 'eu_uk_only', 'whitelist', 'blacklist']),
  countries: z.array(countryCode).optional().default([]),
});
export type GeoRules = z.infer<typeof geoRulesSchema>;

export const behaviourSchema = z.object({
  rePromptAfterDays: z.number().int().min(1).max(1825),
  honourGpc: z.boolean(),
  autoDenyOnDismiss: z.boolean(),
  crossSubdomainConsent: z.boolean(),
});
export type BannerBehaviour = z.infer<typeof behaviourSchema>;

export const bannerDraftSchema = z
  .object({
    layout: layoutSchema,
    locale_default: localeCode,
    locales_enabled: z.array(localeCode).min(1),
    colors: bannerColorsSchema,
    copy: z.record(localeCode, bannerCopySchema),
    gcm_defaults: gcmDefaultsSchema,
    geo_rules: geoRulesSchema,
    behaviour: behaviourSchema,
  })
  .refine((draft) => draft.locales_enabled.includes(draft.locale_default), {
    path: ['locale_default'],
    message: 'The default locale must be listed in locales_enabled.',
  })
  .refine((draft) => draft.locales_enabled.every((locale) => locale in draft.copy), {
    path: ['copy'],
    message: 'Every enabled locale must have copy defined.',
  });
export type BannerDraftInput = z.infer<typeof bannerDraftSchema>;
