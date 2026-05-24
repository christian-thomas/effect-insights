import type { BannerConfig, ConsentCategories, StoredConsent } from './types';

/**
 * Public surface mounted at `window.cmp`. Host pages use it for two
 * things in practice:
 *
 *   - "Withdraw consent" link in a privacy footer  →  cmp.withdraw()
 *   - Reading the current state to gate optional UI  →  cmp.getConsent()
 *
 * Everything else (acceptAll / rejectAll / setConsent / openBanner) is
 * there for completeness and for parity with what other CMPs expose, so
 * a host page that's migrating from OneTrust / Cookiebot can do a
 * one-for-one rename instead of re-implementing.
 *
 * The object is installed eagerly during `boot()` so a host script that
 * runs before cmp.js has finished its async work can still hold a
 * reference. Pre-ready calls are either queued (mutations that need
 * config — openBanner, withdraw, set/accept/reject) or return null
 * (reads — getConsent before any consent exists).
 */

export interface CmpApi {
  /**
   * Current stored consent shape, or null if the visitor has never
   * consented. Reads straight from the cookie — no internal cache, so
   * it stays correct even if a parallel tab changes consent.
   */
  getConsent(): ConsentCategories | null;

  /**
   * True once cmp.js' boot has loaded the config (whether that ended
   * in a banner render, a stored-consent short-circuit, a GPC auto-
   * denial, or a 404). False during the brief async window between
   * script load and config arrival.
   */
  isReady(): boolean;

  /**
   * Callback fires once `isReady()` is true. If already ready, the
   * callback is invoked synchronously on the next microtask.
   */
  ready(callback: () => void): void;

  /**
   * Re-opens the banner UI even for visitors with existing consent.
   * Used by "Cookie preferences" footer links. Pre-ready calls are
   * queued and replayed when boot finishes.
   */
  openBanner(): void;

  /**
   * Records a `withdrawn` consent event with everything denied, clears
   * GCM to denied, re-blocks scripts on the next page load, and
   * re-opens the banner so the visitor can re-decide. Per GDPR
   * Article 7(3) the act of withdrawing must be as easy as the act of
   * giving — this is the surface for that.
   */
  withdraw(): void;

  /**
   * Record consent as if the visitor had clicked Accept all. No banner
   * render. Suitable for programmatic flows where the host page knows
   * the visitor has already consented elsewhere.
   */
  acceptAll(): void;

  /**
   * Mirror of acceptAll for the deny path.
   */
  rejectAll(): void;

  /**
   * Record an arbitrary category mix. Necessary is always coerced to
   * true. Triggers a `changed` event.
   */
  setConsent(categories: Partial<ConsentCategories>): void;

  /**
   * Subscribe to consent changes (granted / denied / changed /
   * withdrawn). Returns an unsubscribe function.
   */
  onConsentChange(listener: ConsentChangeListener): () => void;
}

export type ConsentChangeListener = (event: ConsentChangeEvent) => void;

export interface ConsentChangeEvent {
  type: 'granted' | 'denied' | 'changed' | 'withdrawn';
  categories: ConsentCategories;
  consent: StoredConsent | null;
}

interface ApiBindings {
  readonly readStoredConsent: () => StoredConsent | null;
  readonly openBanner: () => void;
  readonly recordConsent: (params: {
    categories: ConsentCategories;
    eventType: ConsentChangeEvent['type'];
  }) => Promise<void>;
  readonly config: BannerConfig;
}

interface QueuedAction {
  readonly run: (bindings: ApiBindings) => void;
}

class CmpApiHost {
  private bindings: ApiBindings | null = null;

  private readonly queue: QueuedAction[] = [];

  private readonly listeners = new Set<ConsentChangeListener>();

  private readonly readyCallbacks: Array<() => void> = [];

  isReady(): boolean {
    return this.bindings !== null;
  }

  getConsent(): ConsentCategories | null {
    const reader = this.bindings?.readStoredConsent;
    const stored = reader ? reader() : null;
    return stored?.categories ?? null;
  }

  ready(callback: () => void): void {
    if (this.bindings !== null) {
      // Async to keep semantics consistent across pre/post-ready paths.
      queueMicrotask(callback);
      return;
    }
    this.readyCallbacks.push(callback);
  }

  openBanner(): void {
    this.runOrQueue((bindings): void => {
      bindings.openBanner();
    });
  }

  withdraw(): void {
    this.runOrQueue((bindings): void => {
      void bindings.recordConsent({ categories: allDeniedCategories(), eventType: 'withdrawn' });
      bindings.openBanner();
    });
  }

  acceptAll(): void {
    this.runOrQueue((bindings): void => {
      void bindings.recordConsent({ categories: allGrantedCategories(), eventType: 'granted' });
    });
  }

  rejectAll(): void {
    this.runOrQueue((bindings): void => {
      void bindings.recordConsent({ categories: allDeniedCategories(), eventType: 'denied' });
    });
  }

  setConsent(categories: Partial<ConsentCategories>): void {
    this.runOrQueue((bindings): void => {
      void bindings.recordConsent({
        categories: normaliseCategories(categories),
        eventType: 'changed',
      });
    });
  }

  onConsentChange(listener: ConsentChangeListener): () => void {
    this.listeners.add(listener);
    return (): void => {
      this.listeners.delete(listener);
    };
  }

  /**
   * Called by cmp.ts once boot has wired everything up. Drains the queue
   * of pre-ready calls and notifies any waiters that registered ready()
   * before this point.
   */
  attachBindings(bindings: ApiBindings): void {
    this.bindings = bindings;

    while (this.queue.length > 0) {
      const next = this.queue.shift();
      next?.run(bindings);
    }

    const callbacks = this.readyCallbacks.splice(0);
    for (const callback of callbacks) {
      queueMicrotask(callback);
    }
  }

  /**
   * Called by cmp.ts after any consent decision. Notifies subscribers
   * and dispatches a DOM CustomEvent for non-JS-API integrations
   * (analytics plugins etc).
   */
  notifyConsentChange(event: ConsentChangeEvent): void {
    for (const listener of this.listeners) {
      try {
        listener(event);
      } catch {
        // A bad subscriber must never break the consent flow.
      }
    }

    if (typeof document !== 'undefined' && typeof CustomEvent !== 'undefined') {
      try {
        document.dispatchEvent(
          new CustomEvent('cmp:consent-change', {
            detail: { type: event.type, categories: event.categories },
          }),
        );
      } catch {
        // Older IE-ish — non-fatal.
      }
    }
  }

  private runOrQueue(action: (bindings: ApiBindings) => void): void {
    if (this.bindings !== null) {
      action(this.bindings);
      return;
    }
    this.queue.push({ run: action });
  }
}

const allGrantedCategories = (): ConsentCategories => ({
  necessary: true,
  preferences: true,
  statistics: true,
  marketing: true,
});

const allDeniedCategories = (): ConsentCategories => ({
  necessary: true,
  preferences: false,
  statistics: false,
  marketing: false,
});

const normaliseCategories = (input: Partial<ConsentCategories>): ConsentCategories => ({
  necessary: true,
  preferences: input.preferences === true,
  statistics: input.statistics === true,
  marketing: input.marketing === true,
});

declare global {
  interface Window {
    cmp?: CmpApi;
  }
}

export const installPublicApi = (): CmpApiHost => {
  const host = new CmpApiHost();

  const api: CmpApi = {
    getConsent: () => host.getConsent(),
    isReady: () => host.isReady(),
    ready: (callback) => {
      host.ready(callback);
    },
    openBanner: () => {
      host.openBanner();
    },
    withdraw: () => {
      host.withdraw();
    },
    acceptAll: () => {
      host.acceptAll();
    },
    rejectAll: () => {
      host.rejectAll();
    },
    setConsent: (categories) => {
      host.setConsent(categories);
    },
    onConsentChange: (listener) => host.onConsentChange(listener),
  };

  if (typeof window !== 'undefined') {
    window.cmp = api;
  }

  return host;
};
