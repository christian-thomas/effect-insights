# Banner A/B testing

::: warning Not shipped yet
A/B testing of banner configurations is on the roadmap — not available
today. This page documents the intended shape.
:::

## What it'll do

Publish two banner configs as variants of the same domain; cmp.js
allocates visitors deterministically (by `anonymous_id` hash) and
records which variant each consent event came from. The admin shows
a results dashboard with:

- Sample size + statistical significance per variant
- Consent rate by category (accept all / reject all / customise saved)
- Conversion-to-tracking-events (proxy for analytics impact)

Allocation honours your geo rules, so a variant intended for the UK
isn't shown to a visitor in Germany.

## Variants you'd actually want to test

- **Layout** — modal vs bar vs corner. Often modal wins on consent rate
  but loses on bounce rate; A/B answers the tradeoff.
- **Copy** — "We use cookies" vs "Help us improve [Brand]" vs blank
  silence. Wording matters more than designers expect.
- **Refusal prominence** — the GDPR requires refusal as easy as
  acceptance. Some clients still want to push the boundaries; A/B
  with both compliant variants and pick the one that performs better
  legally and commercially.

## Why we haven't shipped it yet

Two reasons:

1. **Material-change cost.** Every banner config change today re-
   prompts visitors. A/B requires holding two configs live for the same
   domain simultaneously without re-prompting — which means the
   material-change classification needs extending. Not hard but not
   trivial.
2. **Statistical infrastructure.** Doing variant testing right means a
   proper sequential-testing setup, not naïve p-value chasing. We'd
   rather defer than ship a footgun.

## ETA

Targeted for v1.1. Talk to your account manager if you have a specific
test you'd run; we may build it as a one-off ahead of generalising.
