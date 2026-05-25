import type { A11yAlertScope, A11yAlertThreshold } from '~/types/organisation';

/**
 * Fields the org settings form on /settings can edit. Narrower than
 * `OrganisationInput` (the superuser shape on /organisations) — name +
 * slug stay superuser-only because they're the tenant identity.
 */
export interface CurrentOrganisationInput {
  consent_retention_days?: number | null;
  notification_email?: string | null;
  a11y_alert_threshold?: A11yAlertThreshold | null;
  a11y_alert_scope?: A11yAlertScope;
}
