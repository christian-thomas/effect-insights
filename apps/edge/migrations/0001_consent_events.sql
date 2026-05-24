-- Migration 0001: consent_events
--
-- Canonical schema for the hot consent log written by the edge consent worker
-- and read by Laravel's nightly archive job (ConsentEventsD1RepositoryContract).
--
-- Idempotent (CREATE * IF NOT EXISTS) so this first migration also re-applies
-- safely against the production D1 database that was bootstrapped manually
-- before migrations existed.

CREATE TABLE IF NOT EXISTS consent_events (
  id TEXT PRIMARY KEY,
  domain_public_key TEXT NOT NULL,
  banner_config_version INTEGER NOT NULL,
  anonymous_id TEXT NOT NULL,
  categories_json TEXT NOT NULL,
  gcm_signals_json TEXT NOT NULL,
  country_code TEXT,
  ua_hash TEXT NOT NULL,
  ip_hash TEXT NOT NULL,
  event_type TEXT NOT NULL,
  occurred_at TEXT NOT NULL,
  banner_text_hash TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_consent_events_occurred_at
  ON consent_events(occurred_at);
