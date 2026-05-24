/**
 * Mirror of {@see App\Http\Resources\AccessibilityRuleOverrideResource}.
 * Per-org / per-domain mute for a specific axe-core rule.
 */
export interface AccessibilityRuleOverride {
  readonly id: string;
  readonly ruleId: string;
  // NULL = applies org-wide. Set to a domain id = applies only to that
  // domain's scans.
  readonly domainId: string | null;
  readonly reason: string | null;
  readonly createdByUserId: string | null;
  readonly createdAt: string;
  readonly updatedAt: string;
}

export interface AccessibilityRuleOverrideInput {
  rule_id: string;
  domain_id?: string | null;
  reason?: string | null;
}
