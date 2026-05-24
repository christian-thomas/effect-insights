# Vendor / sub-processor management

::: warning Not shipped yet
Vendor management is on the roadmap — not available today. The platform
already records which canonical cookie maps to which vendor, but there's
no first-class **Vendors** model in admin yet. This page documents the
intended shape.
:::

## What it'll do

A **Vendors** screen will let you maintain the legal information for every
third-party service that drops a cookie on your site:

- Legal entity name + country of operation
- Privacy policy URL
- Data Processing Agreement (DPA) URL
- Categories of data processed (analytics, ads, payment, etc.)
- Transfer mechanism for international flows (SCC, DPF, adequacy)

Sub-processors of those vendors are tracked one level deeper — e.g.
_Google → uses Google Cloud as a sub-processor_, _Stripe → uses AWS as
a sub-processor_ — so your compliance docs show the full chain.

## Why it matters

Under GDPR Article 28, you need a list of every third party processing
personal data on your behalf, plus the legal basis. Today most clients
maintain this in a spreadsheet. Bringing it into the platform means:

- The cookie policy can name vendors instead of just listing cookie names.
- Adding a new override surfaces a _"Which vendor does this belong to?"_
  prompt so the relationship is captured once.
- Quarterly DPA refresh becomes a single export.

## ETA

Targeted for v1.1. Schema headroom has been left on the cookies table
(see `vendor` column) so the migration when it lands will be additive.
