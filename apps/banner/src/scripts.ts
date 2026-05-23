import type { ConsentCategories, CookieCategory } from './types';

/**
 * Script blocking — the standard CMP technique.
 *
 * Site owners author non-essential `<script>` tags with `type="text/plain"`
 * and `data-category="statistics|marketing|preferences"`. The browser refuses
 * to execute scripts with a non-standard MIME type, so they're inert until
 * we re-inject them as real <script> tags below.
 *
 * Pattern in the host page:
 *   <script type="text/plain" data-category="statistics"
 *           src="https://www.googletagmanager.com/gtag/js?id=G-XXXXX"></script>
 *
 *   <script type="text/plain" data-category="marketing">
 *     // inline tracking pixel etc.
 *   </script>
 *
 * After a consent event we walk the DOM and replace every matching node with
 * a real <script> tag. Since the original is removed, re-running unblockScripts
 * is naturally idempotent — there's nothing left to match.
 */

const SELECTOR = 'script[type="text/plain"][data-category]';

const isConsentedCategory = (
  category: string,
  categories: ConsentCategories,
): boolean => {
  if (!(category in categories)) return false;
  return categories[category as CookieCategory] === true;
};

const cloneScript = (original: HTMLScriptElement): HTMLScriptElement => {
  const clone = document.createElement('script');

  for (const attr of Array.from(original.attributes)) {
    if (attr.name === 'type') continue;
    if (attr.name === 'data-category') continue;
    clone.setAttribute(attr.name, attr.value);
  }

  // External scripts use [src]; inline scripts have textContent.
  if (!original.hasAttribute('src') && original.textContent !== null) {
    clone.textContent = original.textContent;
  }

  return clone;
};

/**
 * Unblocks every script in the DOM whose data-category is in the granted set.
 * Returns the count of scripts released — useful for tests + diagnostics.
 */
export const unblockScripts = (categories: ConsentCategories): number => {
  if (typeof document === 'undefined') return 0;

  const blocked = document.querySelectorAll<HTMLScriptElement>(SELECTOR);
  let released = 0;

  blocked.forEach((blockedScript) => {
    const category = blockedScript.getAttribute('data-category');
    if (category === null || !isConsentedCategory(category, categories)) return;

    const replacement = cloneScript(blockedScript);
    blockedScript.parentNode?.replaceChild(replacement, blockedScript);
    released += 1;
  });

  return released;
};

/**
 * Optionally observe the DOM for scripts added after initial paint (SPAs,
 * lazy-loaded third parties) and unblock them as they arrive. Returns a
 * disconnect handle.
 */
export const watchForBlockedScripts = (
  getCategories: () => ConsentCategories | null,
): (() => void) => {
  if (typeof MutationObserver === 'undefined') {
    return () => {};
  }

  const observer = new MutationObserver(() => {
    const categories = getCategories();
    if (categories === null) return;
    unblockScripts(categories);
  });

  observer.observe(document.documentElement, {
    childList: true,
    subtree: true,
  });

  return () => observer.disconnect();
};
