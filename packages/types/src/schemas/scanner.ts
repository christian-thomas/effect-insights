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

// Accessibility (axe-core) scanner payloads. Shared between the edge
// worker's /scan-a11y route (consumer of the request, producer of the
// result) and Laravel's HttpA11yScanner client (the inverse).
export const a11yImpactSchema = z.enum(['critical', 'serious', 'moderate', 'minor']);

export const accessibilityFindingNodeSchema = z.object({
  target: z.array(z.string()).optional(),
  html: z.string().optional(),
  failureSummary: z.string().optional(),
});

export const accessibilityFindingSchema = z.object({
  ruleId: z.string().min(1),
  impact: a11yImpactSchema,
  description: z.string(),
  help: z.string(),
  helpUrl: z.string(),
  url: z.string().url(),
  tags: z.array(z.string()).default([]),
  nodes: z.array(accessibilityFindingNodeSchema).default([]),
});

export const accessibilityScannerResultSchema = z.object({
  urlsDiscovered: z.number().int().nonnegative(),
  urlsScanned: z.number().int().nonnegative(),
  findings: z.array(accessibilityFindingSchema),
});

export type ScannerResultInput = z.infer<typeof scannerResultSchema>;
export type ScannerRequestInput = z.infer<typeof scannerRequestSchema>;
export type AccessibilityFindingInput = z.infer<typeof accessibilityFindingSchema>;
export type AccessibilityScannerResultInput = z.infer<typeof accessibilityScannerResultSchema>;
