/**
 * Mirror of {@see \App\Services\BannerConfig\BannerConfigSigner} on the
 * Laravel side. The two implementations MUST produce byte-identical
 * canonical-JSON output for the same payload — otherwise the worker
 * will reject everything Laravel publishes.
 *
 * Canonicalisation rules:
 *   - Recursively sort object keys (lists preserve order)
 *   - JSON.stringify with no whitespace
 *   - The `signature` field is excluded before hashing
 *
 * HMAC-SHA-256, hex-encoded output.
 */

const encoder = new TextEncoder();

export const verifyBannerConfigSignature = async (
  payload: Record<string, unknown>,
  key: string,
): Promise<boolean> => {
  const signature = typeof payload.signature === 'string' ? payload.signature : '';
  if (signature === '' || signature === 'unsigned') {
    return false;
  }

  const expected = await signBannerConfigPayload(payload, key);
  return timingSafeEqualHex(expected, signature);
};

export const signBannerConfigPayload = async (
  payload: Record<string, unknown>,
  key: string,
): Promise<string> => {
  const { signature: _signature, ...rest } = payload;
  const canonical = canonicaliseJson(rest);
  const cryptoKey = await crypto.subtle.importKey(
    'raw',
    encoder.encode(key),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );
  const signatureBytes = await crypto.subtle.sign('HMAC', cryptoKey, encoder.encode(canonical));
  return bytesToHex(new Uint8Array(signatureBytes));
};

const canonicaliseJson = (value: unknown): string => {
  return JSON.stringify(normalise(value));
};

const normalise = (value: unknown): unknown => {
  if (Array.isArray(value)) {
    return value.map((item) => normalise(item));
  }
  if (value !== null && typeof value === 'object') {
    const entries = Object.entries(value as Record<string, unknown>)
      .toSorted(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
      .map(([k, v]): [string, unknown] => [k, normalise(v)]);
    return Object.fromEntries(entries);
  }
  return value;
};

const bytesToHex = (bytes: Uint8Array): string => {
  let out = '';
  for (let i = 0; i < bytes.length; i++) {
    out += (bytes[i] ?? 0).toString(16).padStart(2, '0');
  }
  return out;
};

const timingSafeEqualHex = (a: string, b: string): boolean => {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) {
    diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  }
  return diff === 0;
};
