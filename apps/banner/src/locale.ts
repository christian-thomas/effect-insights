/**
 * Picks the best matching locale for the current visitor.
 *
 * Order of preference:
 *   1. Any entry in navigator.languages whose primary subtag matches an
 *      enabled locale exactly (e.g. browser says 'en-GB', config has
 *      'en-GB' → pick 'en-GB').
 *   2. Any entry in navigator.languages whose primary subtag matches an
 *      enabled locale's primary subtag (e.g. browser says 'en-GB',
 *      config has only 'en' → pick 'en').
 *   3. fallback locale (typically config.localeDefault).
 *
 * navigator.languages is the closest browser analogue to the
 * Accept-Language header. It's already ordered by visitor preference,
 * which means our first-match-wins loop produces the same outcome a
 * standards-compliant server would.
 *
 * Browser languages are case-insensitively region-suffixed ('en-GB',
 * 'fr-CA'); enabled locales follow the same shape. Primary-subtag
 * comparison is lowercase to be safe.
 */
export const negotiateLocale = (params: {
  enabled: ReadonlyArray<string>;
  fallback: string;
  browserLanguages?: ReadonlyArray<string>;
}): string => {
  const { enabled, fallback } = params;
  const browser = params.browserLanguages ?? readNavigatorLanguages();

  if (browser.length === 0 || enabled.length === 0) {
    return fallback;
  }

  const enabledByLower = new Map<string, string>();
  for (const locale of enabled) {
    enabledByLower.set(locale.toLowerCase(), locale);
  }

  // Pass 1 — exact-tag match (locale + region).
  for (const candidate of browser) {
    const exact = enabledByLower.get(candidate.toLowerCase());
    if (exact !== undefined) {
      return exact;
    }
  }

  // Pass 2 — primary-subtag match. 'en-GB' from the browser matches a
  // configured 'en'; 'fr-CA' matches 'fr'. Walks the browser list in
  // preference order so the visitor's preferred *language* wins even
  // when no region-specific configured locale exists.
  for (const candidate of browser) {
    const primary = candidate.split('-')[0]?.toLowerCase();
    if (!primary) continue;
    const match = enabledByLower.get(primary);
    if (match !== undefined) {
      return match;
    }
    // Also check whether any enabled locale's primary subtag matches —
    // covers 'en-US' browser + 'en-GB' enabled, where neither exact
    // nor enabled-primary-of-browser-primary hits.
    for (const enabledLocale of enabled) {
      const enabledPrimary = enabledLocale.split('-')[0]?.toLowerCase();
      if (enabledPrimary === primary) {
        return enabledLocale;
      }
    }
  }

  return fallback;
};

const readNavigatorLanguages = (): ReadonlyArray<string> => {
  if (typeof navigator === 'undefined') return [];
  const list = navigator.languages;
  if (Array.isArray(list) && list.length > 0) {
    return list;
  }
  const single = navigator.language;
  return single ? [single] : [];
};
