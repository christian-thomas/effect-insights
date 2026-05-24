# Organisations

Effect Insight is a multi-tenant platform. Every Domain, banner config,
consent event, scan, and accessibility scan belongs to an
Organisation (the tenant boundary). Operators belong to one or more
organisations and switch between them via the org-switcher in the
admin sidebar.

This guide covers Organisation administration — the surface superusers
at Effect Digital use to provision and configure tenants. Regular
operators don't see most of this.

## What lives on an Organisation

| Column                     | Default            | Purpose                                                              |
| -------------------------- | ------------------ | -------------------------------------------------------------------- |
| `name`                     | required           | Display name (e.g. "Acme Corp")                                      |
| `slug`                     | required, unique   | URL-safe identifier (e.g. `acme-corp`)                               |
| `consent_retention_days`   | `NULL` (forever)   | Auto-purge consent events past this age. See [Consent records](./consent-records#configurable-retention-per-organisation). |
| `notification_email`       | `NULL`             | Comma-separated alert recipients. Slack-only when blank. See [Notifications → Email recipients](./notifications#email-recipients). |
| `a11y_alert_threshold`     | `NULL` (inherit)   | Per-org override of the global a11y alert impact threshold. See [Accessibility scans → Alert threshold](./accessibility-scans#alert-threshold). |
| `a11y_alert_scope`         | `all`              | Which a11y rule categories count toward the threshold. See [Accessibility scans → Alert scope](./accessibility-scans#alert-scope-wcag-vs-best-practice). |
| `settings`                 | `NULL`             | Reserved JSON column for future per-tenant config.                   |

## REST surface

All organisation endpoints are **superuser-only** (gated by the
`superuser` middleware in `apps/api/routes/api.php`). Regular
operators get 403 even when authed.

```
GET     /api/organisations                Paginated list (all tenants)
POST    /api/organisations                Create a tenant
GET     /api/organisations/{org}          Detail
PATCH   /api/organisations/{org}          Update any column
DELETE  /api/organisations/{org}          Remove (cascades — see warning below)
```

Plus one endpoint available to every authed user:

```
POST    /api/organisations/switch         Switch the current session's active org
```

Body shape for `switch`:

```json
{ "organisation_id": "uuid" }
```

The endpoint validates that the calling user is actually a member of
the target org, then rebinds the session's `current_organisation_id`.
The next request reads from the new tenant boundary; the admin SPA
re-keys its layout slot wrapper on `auth.organisationCacheKey` so
every page subtree re-mounts on switch.

### Creating a tenant

```bash
curl -X POST https://api.effectinsight.com/api/organisations \
  -H "Content-Type: application/json" \
  -H "Cookie: <superuser sanctum session>" \
  -d '{
    "name": "Acme Corp",
    "slug": "acme-corp",
    "consent_retention_days": 365,
    "notification_email": "ops@acme.example",
    "a11y_alert_threshold": "serious",
    "a11y_alert_scope": "wcag_only"
  }'
```

Returns the created `Organisation` resource. From there:

1. Attach a user (superusers can do this via the admin's user-management
   page; today, do it via `php artisan tinker` and `$org->users()->attach($user, ['role' => OrganisationRole::Owner])`).
2. The user logs in, switches into the new org, and starts adding
   domains.

### Updating a tenant

`PATCH` accepts any subset of the fillable columns:

```bash
curl -X PATCH https://api.effectinsight.com/api/organisations/<uuid> \
  -H "Content-Type: application/json" \
  -H "Cookie: <sanctum session>" \
  -d '{ "consent_retention_days": 90, "a11y_alert_scope": "wcag_only" }'
```

Validation:

- `slug` must remain unique across orgs.
- `consent_retention_days` must be 1-3650 (or `null` for indefinite).
- `a11y_alert_threshold` must be one of
  `critical | serious | moderate | minor` (or `null` to inherit).
- `a11y_alert_scope` must be `all` or `wcag_only` (default `all`).
- `notification_email` is a string up to 320 chars; addresses are
  validated by the splitter in `Organisation::notificationEmails()`,
  so junk entries silently filter out at read time rather than failing
  validation at write time.

### Deleting a tenant

::: warning This is destructive
Deleting an organisation cascades through:

- Every domain in the tenant
- Every banner config (draft + published + history)
- Every scan + finding
- Every accessibility scan + finding
- Every consent event (both in MySQL and any uncommitted D1 rows)
- Every cookie override + accessibility rule override
- Every user-org membership row

There's no soft-delete. Export the consent log via
[`/api/consent-events.csv?domain_id=...`](./consent-records#bulk-csv-export)
first if you need to retain it.
:::

```bash
curl -X DELETE https://api.effectinsight.com/api/organisations/<uuid> \
  -H "Cookie: <superuser sanctum session>"
```

Returns 204 No Content on success.

## Admin UI

Superusers see an **Organisations** entry in the admin sidebar
(operators don't). The page lists every tenant in a table with edit
+ delete actions. Click "New organisation" or the edit pencil to open
the slideover, which has fields for every column above plus inline
help explaining the trade-offs (e.g. "hard delete" warning on
retention, "leave blank to inherit platform default" on thresholds).

The org-switcher itself lives in the sidebar header — clicking the
current org name opens a menu of every org the current user belongs
to. Switching is one click; the page reloads under the new tenant
scope.

## Multi-tenancy implementation notes

The tenant boundary is enforced server-side by two pieces:

- **`BelongsToOrganisation` trait** on every tenant-scoped Eloquent
  model. Adds a global scope that filters every query by the current
  request's `organisation_id`.
- **`ResolveCurrentOrganisation` middleware** binds the session's
  active org id into the `CurrentOrganisation` singleton at the start
  of each request.

`CurrentOrganisation` is the single source of truth — when superusers
switch tenants, the middleware re-binds it before any controller runs.
Any controller using Eloquent automatically queries the right tenant
without needing to pass org id explicitly.

In tests, `Tests\TenantTestCase::actingAsMember($user, $org)` rebinds
the singleton + session in one call. Don't use plain `actingAs()` for
tenant-scoped feature tests — it skips the middleware chain and silent
cross-tenant leaks become possible.
