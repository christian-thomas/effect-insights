import { z } from 'zod';

export const domainStatusSchema = z.enum(['pending', 'active', 'paused']);
export type DomainStatus = z.infer<typeof domainStatusSchema>;

const fqdnRegex = /^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i;

export const domainInputSchema = z.object({
  fqdn: z
    .string()
    .min(1, 'FQDN is required')
    .max(253)
    .regex(fqdnRegex, 'Enter a valid fully-qualified domain name'),
  status: domainStatusSchema.optional(),
});

export type DomainInput = z.infer<typeof domainInputSchema>;

export interface Domain {
  readonly id: string;
  readonly fqdn: string;
  readonly apexDomain: string;
  readonly status: DomainStatus;
  readonly publicKey: string;
  readonly settings: Record<string, unknown> | null;
  readonly createdAt: string;
  readonly updatedAt: string;
}
