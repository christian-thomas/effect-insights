/**
 * Detects the host page's CSP nonce so we can stamp it on every script
 * and style element cmp.js injects. Without this, strict-CSP sites that
 * use a nonce-allowlist would silently block the banner styles and the
 * scripts we re-inject post-consent.
 *
 * Lookup order:
 *   1. <meta name="csp-nonce" content="…"> — the documented convention
 *      most strict-CSP frameworks (Rails, Next.js, Symfony, Laravel via
 *      Spatie) emit. Preferred because it's explicit + stable.
 *   2. document.currentScript.nonce — the nonce the browser applied to
 *      cmp.js itself. Browsers wipe the `nonce` HTML attribute after
 *      parsing (to thwart CSS-based exfiltration) but keep it on the
 *      DOM property, so we read it from there.
 *   3. The script tag the embed sites uses (data-domain-id) — same
 *      property-not-attribute caveat.
 *
 * Returns `null` when no nonce is in play — we don't fabricate one.
 */
export const detectCspNonce = (currentScript: HTMLScriptElement | null): string | null => {
  if (typeof document === 'undefined') return null;

  const meta = document.querySelector<HTMLMetaElement>('meta[name="csp-nonce"]');
  const fromMeta = meta?.content;
  if (fromMeta && fromMeta.length > 0) {
    return fromMeta;
  }

  // `document.currentScript` is null inside async/module contexts, hence
  // the caller-provided fallback (the embed script tag itself).
  const fromCurrent = (document.currentScript as HTMLScriptElement | null)?.nonce;
  if (fromCurrent && fromCurrent.length > 0) {
    return fromCurrent;
  }

  const fromEmbed = currentScript?.nonce;
  if (fromEmbed && fromEmbed.length > 0) {
    return fromEmbed;
  }

  return null;
};

/**
 * Applies the nonce to an element using both the DOM property and the
 * HTML attribute. The property is what the browser actually checks
 * against CSP; the attribute is helpful for debugging in dev tools
 * (and harmless once the browser strips it).
 */
export const applyNonce = (element: HTMLElement, nonce: string | null): void => {
  if (nonce === null || nonce === '') return;
  (element as HTMLElement & { nonce?: string }).nonce = nonce;
  element.setAttribute('nonce', nonce);
};
