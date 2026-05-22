import type { BannerConfig } from '@effect-cmp/types/banner';

const SCRIPT_TAG_ATTR = 'data-domain-id';
const CMP_BASE_URL = 'https://assets.effectcmp.com';

const findScriptTag = (): HTMLScriptElement | null => {
  const scripts = document.querySelectorAll<HTMLScriptElement>(
    `script[${SCRIPT_TAG_ATTR}]`,
  );
  return scripts.item(scripts.length - 1) ?? null;
};

const render = (config: BannerConfig, country: string | null): void => {
  // TODO: render banner UI, wire GCM defaults, hook script-blocking.
  if (typeof console !== 'undefined' && typeof console.info === 'function') {
    console.info(
      '[EffectCMP] booted for',
      config.domainPublicKey,
      'country',
      country,
    );
  }
};

const boot = async (): Promise<void> => {
  try {
    const tag = findScriptTag();
    if (!tag) return;

    const domainId = tag.getAttribute(SCRIPT_TAG_ATTR);
    if (!domainId) return;

    const configUrl = `${CMP_BASE_URL}/config/${encodeURIComponent(domainId)}`;
    const response = await fetch(configUrl, {
      credentials: 'omit',
      cache: 'no-store',
    });

    if (!response.ok) return;

    const payload = (await response.json()) as {
      config: BannerConfig;
      country: string | null;
    };

    render(payload.config, payload.country);
  } catch {
    // Banner must never throw uncaught — bail silently
  }
};

void boot();
