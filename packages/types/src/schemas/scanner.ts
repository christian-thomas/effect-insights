import { z } from 'zod';

export const findingPartySchema = z.enum(['first', 'third']);

export const observedCookieSchema = z.object({
  name: z.string().min(1),
  domain: z.string().nullable(),
  valuePattern: z.string().optional(),
  party: findingPartySchema,
  firstSeenUrl: z.string().optional(),
});

export const observedAssetSchema = z.object({
  name: z.string().min(1),
  party: findingPartySchema,
  firstSeenUrl: z.string().optional(),
});

export const scannerResultSchema = z.object({
  urlsDiscovered: z.number().int().nonnegative(),
  urlsScanned: z.number().int().nonnegative(),
  cookies: z.array(observedCookieSchema),
  scripts: z.array(observedAssetSchema),
  iframes: z.array(observedAssetSchema),
  requests: z.array(observedAssetSchema),
});

export const scannerRequestSchema = z.object({
  urls: z.array(z.string().url()).min(1).max(200),
  domainFqdn: z.string().min(1),
});

export type ScannerResultInput = z.infer<typeof scannerResultSchema>;
export type ScannerRequestInput = z.infer<typeof scannerRequestSchema>;
