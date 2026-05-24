import type { BannerColors, BannerLayout } from './types';

const PREFIX = 'tracewatch';

const layoutPositioning = (layout: BannerLayout): string => {
  if (layout === 'modal') {
    return `
.${PREFIX}-shell {
  position: fixed; inset: 0;
  background: rgba(0, 0, 0, 0.45);
  display: flex; align-items: center; justify-content: center;
  padding: 16px;
}
.${PREFIX}-card { max-width: 540px; width: 100%; max-height: 90vh; overflow-y: auto; }
    `;
  }
  if (layout === 'bar-top' || layout === 'bar-bottom') {
    const position = layout === 'bar-top' ? 'top: 0' : 'bottom: 0';
    const shadow =
      layout === 'bar-top' ? '0 4px 12px rgba(0, 0, 0, 0.08)' : '0 -4px 12px rgba(0, 0, 0, 0.08)';
    return `
.${PREFIX}-shell {
  position: fixed; left: 0; right: 0; ${position};
  display: flex; justify-content: center;
}
.${PREFIX}-card {
  width: 100%; max-width: 1280px;
  border-radius: 0;
  box-shadow: ${shadow};
}
    `;
  }
  // corner
  return `
.${PREFIX}-shell {
  position: fixed; bottom: 16px; right: 16px;
}
.${PREFIX}-card {
  width: 380px; max-width: calc(100vw - 32px); max-height: 80vh; overflow-y: auto;
}
@media (max-width: 480px) {
  .${PREFIX}-shell { left: 16px; right: 16px; }
  .${PREFIX}-card { width: auto; }
}
  `;
};

/**
 * Generates a single scoped CSS string from the config colours + layout.
 * Returned as text so the caller can attach it inside a <style> element
 * inside the banner root, avoiding any pollution of the host page's
 * stylesheet.
 *
 * Class names are prefixed with `tracewatch-` to avoid collisions.
 */
export const generateStyles = (colors: BannerColors, layout: BannerLayout = 'modal'): string =>
  `
.${PREFIX}-shell {
  z-index: 2147483647;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  color: ${colors.text};
}
${layoutPositioning(layout)}
.${PREFIX}-card {
  background: ${colors.background};
  color: ${colors.text};
  border-radius: 8px;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.18);
  padding: 24px;
  box-sizing: border-box;
}
.${PREFIX}-title {
  font-size: 18px; font-weight: 600; margin: 0 0 12px; line-height: 1.4;
}
.${PREFIX}-body {
  font-size: 14px; line-height: 1.55; margin: 0 0 20px;
}
.${PREFIX}-actions {
  display: flex; flex-wrap: wrap; gap: 8px;
}
.${PREFIX}-btn {
  appearance: none; border: 0; cursor: pointer;
  font-size: 14px; font-weight: 500;
  padding: 10px 16px; border-radius: 6px;
  font-family: inherit;
}
.${PREFIX}-btn-primary {
  background: ${colors.primary}; color: ${colors.primaryText};
}
.${PREFIX}-btn-primary:hover { filter: brightness(0.95); }
.${PREFIX}-btn-secondary {
  background: ${colors.secondary}; color: ${colors.secondaryText};
}
.${PREFIX}-btn-secondary:hover { filter: brightness(0.95); }
.${PREFIX}-btn-link {
  background: transparent; color: ${colors.text};
  text-decoration: underline; padding: 10px 8px;
}
.${PREFIX}-categories { margin: 0 0 20px; }
.${PREFIX}-category {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 12px 0; border-bottom: 1px solid rgba(0, 0, 0, 0.08);
}
.${PREFIX}-category:last-child { border-bottom: 0; }
.${PREFIX}-category-meta { flex: 1; }
.${PREFIX}-category-label {
  font-weight: 600; font-size: 14px; margin: 0 0 4px;
  text-transform: capitalize;
}
.${PREFIX}-category-desc {
  font-size: 13px; line-height: 1.4; margin: 0;
  color: ${colors.text}; opacity: 0.75;
}
.${PREFIX}-switch {
  position: relative; width: 36px; height: 20px;
  background: rgba(0, 0, 0, 0.2); border-radius: 999px;
  cursor: pointer; flex-shrink: 0; margin-top: 2px;
  transition: background 0.15s;
}
.${PREFIX}-switch[data-on="true"] { background: ${colors.primary}; }
.${PREFIX}-switch[data-disabled="true"] {
  cursor: not-allowed; opacity: 0.6;
}
.${PREFIX}-switch::after {
  content: ""; position: absolute; top: 2px; left: 2px;
  width: 16px; height: 16px; background: #fff;
  border-radius: 50%; transition: transform 0.15s;
}
.${PREFIX}-switch[data-on="true"]::after { transform: translateX(16px); }
`.trim();

export const cls = (suffix: string): string => `${PREFIX}-${suffix}`;
