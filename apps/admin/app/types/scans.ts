import type { CookieCategory } from '~/types/cookies';

export type ScanStatus = 'queued' | 'running' | 'completed' | 'partial' | 'failed';

export type ScanTrigger = 'manual' | 'scheduled';

export type FindingType = 'cookie' | 'script' | 'iframe' | 'network_request';

export type FindingParty = 'first' | 'third';

export type FindingCategorySource = 'canonical' | 'organisation_override' | 'domain_override';

export interface Scan {
  readonly id: string;
  readonly domainId: string;
  readonly status: ScanStatus;
  readonly trigger: ScanTrigger;
  readonly urlsDiscovered: number;
  readonly urlsScanned: number;
  readonly startedAt: string | null;
  readonly finishedAt: string | null;
  readonly errorLog: Record<string, unknown> | null;
  // URLs the scan tried and gave up on (per-URL worker failures or
  // chunk-job retry exhaustion). Non-empty implies status === 'partial'
  // (some succeeded) or 'failed' (none succeeded); empty array on a
  // clean completion. Drives the "Retry failed URLs (N)" button.
  readonly failedUrls: ReadonlyArray<string>;
  readonly createdAt: string;
}

export interface ScanFinding {
  readonly id: string;
  readonly scanId: string;
  readonly type: FindingType;
  readonly name: string;
  readonly domainSetOn: string | null;
  readonly party: FindingParty;
  readonly category: CookieCategory | null;
  readonly categorySource: FindingCategorySource | null;
  readonly purposeText: string | null;
  readonly firstSeenUrl: string | null;
  readonly cookieId: string | null;
  readonly cookieOverrideId: string | null;
}
