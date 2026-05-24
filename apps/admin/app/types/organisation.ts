export interface Organisation {
  readonly id: string;
  readonly name: string;
  readonly slug: string;
  // NULL = retain consent events indefinitely (the platform default).
  // Positive integer = nightly purge of anything older than N days.
  readonly consentRetentionDays: number | null;
  // NULL = Slack only. Comma-separated address list = also fire mail
  // notifications for all four alert types (unknown cookies, scan
  // failures, accessibility violations, a11y scan failures).
  readonly notificationEmail: string | null;
}

export interface OrganisationInput {
  name: string;
  slug: string;
  consent_retention_days?: number | null;
  notification_email?: string | null;
}
