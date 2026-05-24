import type { ScanStatus, ScanTrigger } from '~/types/scans';

export type A11yImpact = 'critical' | 'serious' | 'moderate' | 'minor';

/**
 * Mirror of {@see App\Http\Resources\AccessibilityScanResource}.
 */
export interface AccessibilityScan {
  readonly id: string;
  readonly domainId: string;
  readonly status: ScanStatus;
  readonly trigger: ScanTrigger;
  readonly urlsDiscovered: number;
  readonly urlsScanned: number;
  readonly violationsTotal: number;
  readonly violationsByImpact: Readonly<Record<A11yImpact, number>> | null;
  readonly startedAt: string | null;
  readonly finishedAt: string | null;
  readonly errorLog: Record<string, unknown> | null;
  readonly createdAt: string;
}

/**
 * Mirror of {@see App\Http\Resources\AccessibilityFindingResource}.
 * One row per axe-core violation observed during the scan.
 */
export interface AccessibilityFindingNode {
  readonly target?: ReadonlyArray<string>;
  readonly html?: string;
  readonly failureSummary?: string;
}

export interface AccessibilityFinding {
  readonly id: string;
  readonly accessibilityScanId: string;
  readonly ruleId: string;
  readonly impact: A11yImpact;
  readonly description: string;
  readonly helpText: string;
  readonly helpUrl: string;
  readonly url: string;
  readonly tags: ReadonlyArray<string>;
  readonly nodes: ReadonlyArray<AccessibilityFindingNode>;
}
