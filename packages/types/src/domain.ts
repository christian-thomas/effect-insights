import { z } from 'zod';

export const domainStatusSchema = z.enum(['pending', 'active', 'paused']);
export type DomainStatus = z.infer<typeof domainStatusSchema>;

export const scanCadenceSchema = z.enum(['weekly', 'monthly', 'never']);
export type ScanCadence = z.infer<typeof scanCadenceSchema>;

const fqdnRegex = /^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i;

export const domainInputSchema = z.object({
  fqdn: z
    .string()
    .min(1, 'FQDN is required')
    .max(253)
    .regex(fqdnRegex, 'Enter a valid fully-qualified domain name'),
  status: domainStatusSchema.optional(),
  // snake_case matches the Laravel FormRequest field name. Output Domain
  // type exposes the camelCase `scanCadence` / `a11yScanCadence` for
  // read-side ergonomics.
  scan_cadence: scanCadenceSchema.optional(),
  a11y_scan_cadence: scanCadenceSchema.optional(),
});

export type DomainInput = z.infer<typeof domainInputSchema>;

export interface Domain {
  readonly id: string;
  readonly fqdn: string;
  readonly apexDomain: string;
  readonly status: DomainStatus;
  readonly scanCadence: ScanCadence;
  readonly lastScannedAt: string | null;
  readonly a11yScanCadence: ScanCadence;
  readonly lastA11yScannedAt: string | null;
  readonly publicKey: string;
  readonly settings: Record<string, unknown> | null;
  readonly createdAt: string;
  readonly updatedAt: string;
}
