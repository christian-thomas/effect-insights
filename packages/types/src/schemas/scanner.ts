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
  // URLs the worker tried to scan and gave up on per-URL (timeouts,
  // navigation errors, etc.). The chunk job still completes successfully;
  // Laravel appends these to scans.failed_urls for the "Retry failed
  // URLs" admin flow.
  failedUrls: z.array(z.string()).default([]),
});

export const scannerRequestSchema = z.object({
  urls: z.array(z.string().url()).min(1).max(200),
  domainFqdn: z.string().min(1),
  // Identifiers used by the worker to scope R2 keys (a11y screenshots
  // live at `<organisationId>/<scanId>/<uuid>.png`). Both endpoints
  // accept them so the schema stays single-source; /scan ignores them.
  organisationId: z.string().uuid(),
  scanId: z.string().uuid(),
});

// Accessibility (axe-core) scanner payloads. Shared between the edge
// worker's /scan-a11y route (consumer of the request, producer of the
// result) and Laravel's HttpA11yScanner client (the inverse).
export const a11yImpactSchema = z.enum(['critical', 'serious', 'moderate', 'minor']);

export const accessibilityFindingNodeSchema = z.object({
  target: z.array(z.string()).optional(),
  html: z.string().optional(),
  failureSummary: z.string().optional(),
  // R2 key for the element screenshot the worker uploaded — shape
  // `<organisationId>/<scanId>/<uuid>.png`. Laravel persists this to
  // AccessibilityFinding.screenshot_key and serves the bytes through
  // ShowFindingScreenshot. Optional because element resolution can
  // fail (display:none, off-viewport, selector mismatch).
  screenshotKey: z.string().optional(),
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
  // Same shape + semantics as scannerResultSchema.failedUrls.
  failedUrls: z.array(z.string()).default([]),
});

export type ScannerResultInput = z.infer<typeof scannerResultSchema>;
export type ScannerRequestInput = z.infer<typeof scannerRequestSchema>;
export type AccessibilityFindingInput = z.infer<typeof accessibilityFindingSchema>;
export type AccessibilityScannerResultInput = z.infer<typeof accessibilityScannerResultSchema>;
