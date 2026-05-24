import { installPublicApi } from './api';
import { fetchConfig } from './config';
import {
  allDenied,
  allGranted,
  buildConsentEvent,
  generateAnonymousId,
  isStoredConsentStillValid,
  postConsentEvent,
  readStoredConsent,
  writeStoredConsent,
  type ConsentPostBody,
} from './consent';
import { detectCspNonce } from './csp';
import { renderBanner } from './dom';
import { installDefaults, sendUpdate } from './gcm';
import { negotiateLocale } from './locale';
import { flushOutbox, queueOutboxEntry } from './outbox';
import { collectNavigatorSignals, sendRumBeacon, startPaintTimer } from './rum';
import { unblockScripts, watchForBlockedScripts } from './scripts';
import { generateStyles } from './styles';
import type { BannerConfig, ConsentCategories } from './types';

const SCRIPT_TAG_ATTR = 'data-domain-id';
const BASE_ATTR = 'data-cmp-base';
// Override the POST /consent target separately from the config base. In prod
// both URLs share `assets.effectcmp.com`; locally each wrangler-dev worker
// listens on its own port so the two URLs diverge. Falls back to BASE_ATTR
// when unset, so production embeds keep working unchanged.
const CONSENT_BASE_ATTR = 'data-cmp-consent-base';
const INLINE_CONFIG_ATTR = 'data-cmp-config';
const PREVIEW_MODE_ATTR = 'data-cmp-preview';
const DEFAULT_BASE = 'https://assets.effectcmp.com';

const apiHost = installPublicApi();

const findScriptTag = (): HTMLScriptElement | null => {
  const scripts = document.querySelectorAll<HTMLScriptElement>(`script[${SCRIPT_TAG_ATTR}]`);
  // Last match wins — pages with multiple embeds (re-embedded subdomain
  // pattern) should use the most recently-loaded script's config.
  return scripts.item(scripts.length - 1) ?? null;
};

// Global Privacy Control — browser-level signal that the visitor does not
// consent to tracking. Currently exposed by Brave, DuckDuckGo, Firefox (with
// the privacy.gpc.enabled flag), and a few extensions. Spec at
// https://privacycg.github.io/gpc-spec/.
const isGpcActive = (): boolean => {
  if (typeof navigator === 'undefined') return false;
  return (
    (navigator as Navigator & { globalPrivacyControl?: boolean }).globalPrivacyControl === true
  );
};

const readInlineConfig = (tag: HTMLScriptElement): BannerConfig | null => {
  const raw = tag.getAttribute(INLINE_CONFIG_ATTR);
  if (raw === null || raw === '') return null;
  try {
    return JSON.parse(raw) as BannerConfig;
  } catch {
    // Malformed JSON — fall through to the normal fetch path.
    return null;
  }
};

interface BannerHandles {
  root: HTMLElement;
  destroy: () => void;
}

const boot = async (): Promise<void> => {
  const paintTimer = startPaintTimer();
  try {
    const tag = findScriptTag();
    if (!tag) return;

    const domainId = tag.getAttribute(SCRIPT_TAG_ATTR);
    if (!domainId) return;

    const baseUrl = (tag.getAttribute(BASE_ATTR) ?? DEFAULT_BASE).replace(/\/$/, '');
    const consentBaseUrl = (tag.getAttribute(CONSENT_BASE_ATTR) ?? baseUrl).replace(/\/$/, '');
    const previewMode = tag.hasAttribute(PREVIEW_MODE_ATTR);
    const cspNonce = detectCspNonce(tag);

    // Drain any consent events that failed to POST on previous page loads.
    // Fire-and-forget so the banner paint isn't blocked on it.
    if (!previewMode) {
      void flushOutbox();
    }

    const inlineConfig = readInlineConfig(tag);
    let config: BannerConfig;
    let country: string | null;

    if (inlineConfig !== null) {
      config = inlineConfig;
      country = null;
    } else {
      const response = await fetchConfig(baseUrl, domainId);
      if (response === null) return;
      config = response.config;
      country = response.country;
    }

    // GCM defaults FIRST — must reach the dataLayer before any GA/Ads script
    // on the host page. Synchronous push, no awaits between fetchConfig and here.
    installDefaults(config.gcmDefaults);

    let mountedHandles: BannerHandles | null = null;

    const recordConsent = async (params: {
      categories: ConsentCategories;
      eventType: ConsentPostBody['eventType'];
    }): Promise<void> => {
      const { categories, eventType } = params;

      const stored = readStoredConsent();
      const anonymousId = stored?.anonymousId ?? generateAnonymousId();

      writeStoredConsent(
        {
          anonymousId,
          materialVersion: config.materialVersion,
          categories,
          consentedAt: new Date().toISOString(),
        },
        config,
      );

      sendUpdate(categories);
      unblockScripts(categories, cspNonce);

      const event = buildConsentEvent({ anonymousId, config, categories, country, eventType });
      try {
        await postConsentEvent(consentBaseUrl, event);
      } catch {
        // Network error or 5xx — queue for the next page load. The edge
        // worker's INSERT OR IGNORE makes a re-send safe even if a previous
        // attempt did land but the response came back as an error.
        queueOutboxEntry(event, consentBaseUrl);
      }

      apiHost.notifyConsentChange({
        type: eventType,
        categories,
        consent: readStoredConsent(),
      });
    };

    const buildBannerHandles = (initial: ConsentCategories): BannerHandles | null => {
      // Honour the visitor's browser language preferences. Falls back to
      // localeDefault when no Accept-Language entry matches any enabled
      // locale, which is the same outcome as the old always-default
      // behaviour.
      const negotiated = negotiateLocale({
        enabled: config.localesEnabled,
        fallback: config.localeDefault,
      });
      const copy =
        config.copy[negotiated] ??
        config.copy[config.localeDefault] ??
        Object.values(config.copy)[0];
      if (!copy) return null;

      const styleText = generateStyles(config.colors, config.layout);

      const finish = async (
        categories: ConsentCategories,
        eventType: ConsentPostBody['eventType'],
      ): Promise<void> => {
        mountedHandles?.destroy();
        mountedHandles = null;
        if (previewMode) {
          // No POST + no cookie writes — preview is a no-side-effect render.
          return;
        }
        await recordConsent({ categories, eventType });
      };

      return renderBanner({
        copy,
        initial,
        styleText,
        granularity: config.granularity,
        cspNonce,
        // Dismiss-as-denial only when the operator has opted in. Otherwise
        // the visitor must make an explicit choice (no Esc-to-dismiss, no
        // backdrop click). See behaviour.autoDenyOnDismiss in the banner
        // config.
        ...(config.behaviour.autoDenyOnDismiss
          ? {
              onDismiss: (): void => {
                void finish(allDenied(), 'denied');
              },
            }
          : {}),
        onAcceptAll: () => {
          void finish(allGranted(), 'granted');
        },
        onRejectAll: () => {
          void finish(allDenied(), 'denied');
        },
        onSavePreferences: (categories) => {
          void finish(categories, 'changed');
        },
      });
    };

    const mountBanner = (initial: ConsentCategories): void => {
      if (mountedHandles !== null) {
        // Already mounted — re-focus rather than stacking dialogs.
        const focusTarget = mountedHandles.root.querySelector<HTMLElement>('button');
        focusTarget?.focus();
        return;
      }
      const handles = buildBannerHandles(initial);
      if (!handles) return;
      document.body.appendChild(handles.root);
      mountedHandles = handles;
    };

    // Expose the public API now that we know the config + have a mount
    // helper. Pre-ready queued calls (openBanner / withdraw / setConsent
    // invoked before this point by an inline host script) drain here.
    apiHost.attachBindings({
      config,
      readStoredConsent,
      openBanner: () => {
        const stored = readStoredConsent();
        mountBanner(stored?.categories ?? allDenied());
      },
      recordConsent,
    });

    // In preview mode the admin needs to see the banner every refresh, so
    // we skip the cookie-based short-circuit and don't watch / unblock
    // scripts (the iframe doesn't host any real scripts).
    if (!previewMode) {
      // Watch for late-arriving blocked scripts (lazy-loaded third parties on
      // SPAs etc) and unblock them as soon as they appear in the DOM.
      const stored = readStoredConsent();
      watchForBlockedScripts(() => {
        const current = readStoredConsent();
        return current === null ? null : current.categories;
      }, cspNonce);

      // Prior consent still valid? Replay it as an `update`, unblock the
      // already-rendered blocked scripts, and skip the modal entirely.
      if (isStoredConsentStillValid(stored, config) && stored !== null) {
        sendUpdate(stored.categories);
        unblockScripts(stored.categories, cspNonce);
        // RUM beacon for the short-circuit path — typically the fastest,
        // worth tracking separately from the cold-paint path.
        sendRumBeacon(consentBaseUrl, {
          domainPublicKey: config.domainPublicKey,
          paintMs: paintTimer.elapsed(),
          hadStoredConsent: true,
          ...collectNavigatorSignals(),
        });
        return;
      }

      // Global Privacy Control — when the operator has opted in to
      // honouring it AND the visitor's browser is sending the signal AND
      // they have no stored consent yet, treat the GPC signal as a
      // refusal. Record a `denied` consent event so we have an audit
      // trail (the regulator wants proof we received + honoured GPC),
      // write the cookie so we don't re-prompt on every page, and skip
      // the banner. Doesn't override existing stored consent — the
      // visitor's explicit prior choice always wins.
      if (config.behaviour.honourGpc && isGpcActive()) {
        await recordConsent({
          categories: allDenied(),
          eventType: 'denied',
        });
        sendRumBeacon(consentBaseUrl, {
          domainPublicKey: config.domainPublicKey,
          paintMs: paintTimer.elapsed(),
          hadStoredConsent: false,
          ...collectNavigatorSignals(),
        });
        return;
      }
    }

    mountBanner(allDenied());

    // Self-monitoring beacon. Fire-and-forget; never blocks the banner.
    if (!previewMode) {
      sendRumBeacon(consentBaseUrl, {
        domainPublicKey: config.domainPublicKey,
        paintMs: paintTimer.elapsed(),
        hadStoredConsent: false,
        ...collectNavigatorSignals(),
      });
    }
  } catch {
    // The banner must never throw uncaught. A failed banner is worse than
    // no banner — bail silently and let the host page proceed.
  }
};

void boot();
