import { z } from 'zod';

export const cookieCategorySchema = z.enum([
  'necessary',
  'preferences',
  'statistics',
  'marketing',
]);

export const gcmSignalSchema = z.enum(['granted', 'denied']);

export const gcmUpdateSchema = z.object({
  ad_storage: gcmSignalSchema.optional(),
  analytics_storage: gcmSignalSchema.optional(),
  ad_user_data: gcmSignalSchema.optional(),
  ad_personalization: gcmSignalSchema.optional(),
  functionality_storage: gcmSignalSchema.optional(),
  personalization_storage: gcmSignalSchema.optional(),
  security_storage: gcmSignalSchema.optional(),
});

export const consentCategoriesSchema = z.object({
  necessary: z.boolean(),
  preferences: z.boolean(),
  statistics: z.boolean(),
  marketing: z.boolean(),
});

export const consentEventTypeSchema = z.enum([
  'granted',
  'denied',
  'changed',
  'withdrawn',
]);

export const consentEventSchema = z.object({
  id: z.uuid(),
  domainPublicKey: z.string().min(1).max(64),
  bannerConfigVersion: z.number().int().nonnegative(),
  anonymousId: z.string().min(1).max(128),
  categories: consentCategoriesSchema,
  gcmSignals: gcmUpdateSchema,
  countryCode: z.string().length(2).nullable(),
  userAgentHash: z.string().min(1).max(128),
  ipHash: z.string().min(1).max(128),
  eventType: consentEventTypeSchema,
  occurredAt: z.iso.datetime(),
  bannerTextHash: z.string().min(1).max(128),
});

export type ConsentEventInput = z.infer<typeof consentEventSchema>;
