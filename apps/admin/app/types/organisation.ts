export interface Organisation {
  readonly id: string;
  readonly name: string;
  readonly slug: string;
  // NULL = retain consent events indefinitely (the platform default).
  // Positive integer = nightly purge of anything older than N days.
  readonly consentRetentionDays: number | null;
}

export interface OrganisationInput {
  name: string;
  slug: string;
  consent_retention_days?: number | null;
}
