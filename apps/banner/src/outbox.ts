/**
 * localStorage-backed outbox for failed consent POSTs.
 *
 * cmp.js POSTs the consent event to the edge worker on accept/reject.
 * Network errors and 5xx responses would previously be swallowed
 * silently — the visitor's preference would never reach D1 / the
 * Laravel mirror, so the consent log would be incomplete and the
 * regulator wouldn't see proof the visitor was prompted.
 *
 * Now: failed POSTs land in this queue. The next page load (or the
 * next consent action — whichever comes first) drains it best-effort.
 * The edge worker's INSERT OR IGNORE on the event UUID makes retries
 * idempotent: a re-send of an event that did actually persist is a
 * silent no-op on the server.
 *
 * Caps: max 100 queued events (drop oldest), max 5 attempts per event
 * (drop after that). 5 page loads of the visitor browsing the site
 * during a partial CF outage feels generous; anything beyond suggests
 * the worker is permanently broken and the event isn't recoverable
 * from cmp.js alone anyway.
 */

import { postConsentEvent, type ConsentPostBody } from './consent';

const STORAGE_KEY = 'cmp_outbox';
const MAX_ENTRIES = 100;
const MAX_ATTEMPTS = 5;

interface OutboxEntry {
  event: ConsentPostBody;
  consentBaseUrl: string;
  attempts: number;
  queuedAt: string;
}

const isStorageAvailable = (): boolean => {
  try {
    if (typeof localStorage === 'undefined') return false;
    const probe = '__cmp_outbox_probe__';
    localStorage.setItem(probe, '1');
    localStorage.removeItem(probe);
    return true;
  } catch {
    return false;
  }
};

const readOutbox = (): OutboxEntry[] => {
  if (!isStorageAvailable()) return [];
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw === null) return [];
    const parsed = JSON.parse(raw) as unknown;
    return Array.isArray(parsed) ? (parsed as OutboxEntry[]) : [];
  } catch {
    return [];
  }
};

const writeOutbox = (entries: OutboxEntry[]): void => {
  if (!isStorageAvailable()) return;
  try {
    if (entries.length === 0) {
      localStorage.removeItem(STORAGE_KEY);
      return;
    }
    localStorage.setItem(STORAGE_KEY, JSON.stringify(entries));
  } catch {
    // Quota exceeded etc — silent. Worst case: outbox doesn't persist
    // across this page load; the original recordConsent path already
    // applied client-side state (cookie + GCM + unblockScripts).
  }
};

export const queueOutboxEntry = (event: ConsentPostBody, consentBaseUrl: string): void => {
  const entries = readOutbox();
  entries.push({
    event,
    consentBaseUrl,
    attempts: 0,
    queuedAt: new Date().toISOString(),
  });
  // Drop oldest when over cap.
  const trimmed = entries.length > MAX_ENTRIES ? entries.slice(-MAX_ENTRIES) : entries;
  writeOutbox(trimmed);
};

/**
 * Drains the outbox best-effort. Each successful POST removes the entry;
 * failures bump the attempt counter and re-queue until MAX_ATTEMPTS, after
 * which the entry is dropped. Designed to be fire-and-forget on every
 * cmp.js boot — never throws.
 */
export const flushOutbox = async (): Promise<void> => {
  const entries = readOutbox();
  if (entries.length === 0) return;

  const remaining: OutboxEntry[] = [];

  for (const entry of entries) {
    try {
      // eslint-disable-next-line no-await-in-loop -- order matters: replay in queue order, one at a time
      await postConsentEvent(entry.consentBaseUrl, entry.event);
      // Success — drop this entry from the queue.
    } catch {
      const attempts = entry.attempts + 1;
      if (attempts < MAX_ATTEMPTS) {
        remaining.push({ ...entry, attempts });
      }
      // attempts === MAX_ATTEMPTS → drop silently. There's nowhere else
      // to report from cmp.js without making another network call.
    }
  }

  writeOutbox(remaining);
};
