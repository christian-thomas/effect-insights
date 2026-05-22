import { z } from 'zod';

export const layoutSchema = z.enum([
  'modal',
  'bar-top',
  'bar-bottom',
  'corner',
]);
export type BannerLayout = z.infer<typeof layoutSchema>;

const hex = z
  .string()
  .regex(/^#[0-9a-fA-F]{6}$/, 'Use a 6-digit hex colour, e.g. #2563eb');

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

export const bannerDraftSchema = z.object({
  layout: layoutSchema,
  colors: bannerColorsSchema,
  copy: bannerCopySchema,
});
export type BannerDraftInput = z.infer<typeof bannerDraftSchema>;
