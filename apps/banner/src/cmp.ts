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
import { renderBanner } from './dom';
import { installDefaults, sendUpdate } from './gcm';
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

const findScriptTag = (): HTMLScriptElement | null => {
  const scripts = document.querySelectorAll<HTMLScriptElement>(`script[${SCRIPT_TAG_ATTR}]`);
  // Last match wins — pages with multiple embeds (re-embedded subdomain
  // pattern) should use the most recently-loaded script's config.
  return scripts.item(scripts.length - 1) ?? null;
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

const recordConsent = async (params: {
  config: BannerConfig;
  country: string | null;
  categories: ConsentCategories;
  consentBaseUrl: string;
  eventType: ConsentPostBody['eventType'];
}): Promise<void> => {
  const { config, country, categories, consentBaseUrl, eventType } = params;

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
  unblockScripts(categories);

  try {
    await postConsentEvent(
      consentBaseUrl,
      buildConsentEvent({ anonymousId, config, categories, country, eventType }),
    );
  } catch {
    // Edge already has the canonical write; mirror is best-effort.
    // A follow-up iteration will queue retries via a localStorage outbox.
  }
};

const boot = async (): Promise<void> => {
  try {
    const tag = findScriptTag();
    if (!tag) return;

    const domainId = tag.getAttribute(SCRIPT_TAG_ATTR);
    if (!domainId) return;

    const baseUrl = (tag.getAttribute(BASE_ATTR) ?? DEFAULT_BASE).replace(/\/$/, '');
    const consentBaseUrl = (tag.getAttribute(CONSENT_BASE_ATTR) ?? baseUrl).replace(/\/$/, '');
    const previewMode = tag.hasAttribute(PREVIEW_MODE_ATTR);

    // Inline config — used by the admin preview iframe. Bypasses the edge
    // fetch entirely; the host page passes the draft config as a JSON
    // string in data-cmp-config so reviewers can see unpublished changes.
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
      });

      // Prior consent still valid? Replay it as an `update`, unblock the
      // already-rendered blocked scripts, and skip the modal entirely.
      if (isStoredConsentStillValid(stored, config) && stored !== null) {
        sendUpdate(stored.categories);
        unblockScripts(stored.categories);
        return;
      }
    }

    const copy = config.copy[config.localeDefault] ?? Object.values(config.copy)[0];
    if (!copy) return;

    const styleText = generateStyles(config.colors, config.layout);
    let handles: { root: HTMLElement; destroy: () => void } | null = null;

    const finish = async (
      categories: ConsentCategories,
      eventType: ConsentPostBody['eventType'],
    ): Promise<void> => {
      handles?.destroy();
      if (previewMode) {
        // No POST + no cookie writes — preview is a no-side-effect render.
        return;
      }
      await recordConsent({ config, country, categories, consentBaseUrl, eventType });
    };

    handles = renderBanner({
      copy,
      initial: allDenied(),
      styleText,
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

    document.body.appendChild(handles.root);
  } catch {
    // The banner must never throw uncaught. A failed banner is worse than
    // no banner — bail silently and let the host page proceed.
  }
};

void boot();
