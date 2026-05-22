import type { ConfigResponse } from './types';

/**
 * Fetches the banner config for a given domain public_key from the edge
 * worker. The response wraps the BannerConfig alongside the country code
 * the edge derived from `CF-IPCountry`, used for geo gating.
 */
export const fetchConfig = async (
  baseUrl: string,
  publicKey: string,
): Promise<ConfigResponse | null> => {
  const url = `${baseUrl}/config/${encodeURIComponent(publicKey)}`;
  const response = await fetch(url, {
    credentials: 'omit',
    cache: 'no-store',
  });

  if (!response.ok) {
    return null;
  }

  return (await response.json()) as ConfigResponse;
};
