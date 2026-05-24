/**
 * Real-user-monitoring beacon. Measures banner paint time and ships it
 * to the consent worker's /rum endpoint best-effort.
 *
 * Goal: catch p95 banner-paint regressing past the <50ms budget in
 * production before clients notice. Nothing fancy yet — just a single
 * fire-and-forget POST per banner render. Server-side aggregation is
 * a separate piece (see open list).
 *
 * RUM is best-effort: every code path here swallows failures. A broken
 * beacon must never block the banner.
 */

export interface RumBeacon {
  domainPublicKey: string;
  paintMs: number;
  hadStoredConsent: boolean;
  // Optional environment signals — undefined when unavailable in the
  // host browser. Reduces log volume for older clients while keeping
  // the schema additive.
  effectiveConnectionType?: string;
  deviceMemoryGb?: number;
}

interface NavigatorWithExtras {
  readonly connection?: { readonly effectiveType?: string };
  readonly deviceMemory?: number;
}

export const startPaintTimer = (): { elapsed: () => number } => {
  const start = typeof performance !== 'undefined' ? performance.now() : Date.now();
  return {
    elapsed: (): number => {
      const now = typeof performance !== 'undefined' ? performance.now() : Date.now();
      return Math.round(now - start);
    },
  };
};

export const sendRumBeacon = (baseUrl: string, beacon: RumBeacon): void => {
  if (typeof fetch === 'undefined') return;

  try {
    // keepalive lets the request survive a same-tick navigation if the
    // user clicks a link the moment the banner paints. No await — we
    // never want to block on RUM.
    void fetch(`${baseUrl}/rum`, {
      method: 'POST',
      credentials: 'omit',
      keepalive: true,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(beacon),
    }).catch((): void => {
      // Best-effort.
    });
  } catch {
    // Best-effort.
  }
};

export const collectNavigatorSignals = (): {
  effectiveConnectionType?: string;
  deviceMemoryGb?: number;
} => {
  if (typeof navigator === 'undefined') return {};

  const nav = navigator as Navigator & NavigatorWithExtras;
  // Build the object property-at-a-time so we never assign `undefined` to
  // an optional field (exactOptionalPropertyTypes forbids it). Older
  // browsers will simply omit the signal.
  const signals: { effectiveConnectionType?: string; deviceMemoryGb?: number } = {};
  if (nav.connection?.effectiveType !== undefined) {
    signals.effectiveConnectionType = nav.connection.effectiveType;
  }
  if (nav.deviceMemory !== undefined) {
    signals.deviceMemoryGb = nav.deviceMemory;
  }
  return signals;
};
