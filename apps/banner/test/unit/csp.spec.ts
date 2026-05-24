import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { applyNonce, detectCspNonce } from '../../src/csp';

const resetDom = (): void => {
  document.documentElement.innerHTML = '<head></head><body></body>';
};

describe('csp.detectCspNonce', () => {
  beforeEach(resetDom);
  afterEach(resetDom);

  it('reads the nonce from <meta name="csp-nonce"> when present', () => {
    const meta = document.createElement('meta');
    meta.setAttribute('name', 'csp-nonce');
    meta.setAttribute('content', 'meta-nonce-abc');
    document.head.appendChild(meta);

    expect(detectCspNonce(null)).toBe('meta-nonce-abc');
  });

  it('falls back to the embed script tag nonce property when no meta', () => {
    const script = document.createElement('script');
    // Mimic what the browser does — set the property, wipe the attribute.
    (script as HTMLScriptElement & { nonce?: string }).nonce = 'embed-nonce-xyz';
    document.body.appendChild(script);

    expect(detectCspNonce(script)).toBe('embed-nonce-xyz');
  });

  it('prefers meta-tag nonce over embed-script nonce when both exist', () => {
    const meta = document.createElement('meta');
    meta.setAttribute('name', 'csp-nonce');
    meta.setAttribute('content', 'from-meta');
    document.head.appendChild(meta);

    const script = document.createElement('script');
    (script as HTMLScriptElement & { nonce?: string }).nonce = 'from-script';
    document.body.appendChild(script);

    expect(detectCspNonce(script)).toBe('from-meta');
  });

  it('returns null when no nonce source is available', () => {
    expect(detectCspNonce(null)).toBeNull();
  });

  it('returns null when meta content is empty', () => {
    const meta = document.createElement('meta');
    meta.setAttribute('name', 'csp-nonce');
    meta.setAttribute('content', '');
    document.head.appendChild(meta);

    expect(detectCspNonce(null)).toBeNull();
  });
});

describe('csp.applyNonce', () => {
  beforeEach(resetDom);

  it('sets both property and attribute when nonce is provided', () => {
    const element = document.createElement('script');
    applyNonce(element, 'my-nonce');

    expect((element as HTMLScriptElement & { nonce?: string }).nonce).toBe('my-nonce');
    expect(element.getAttribute('nonce')).toBe('my-nonce');
  });

  it('is a no-op when nonce is null', () => {
    const element = document.createElement('script');
    applyNonce(element, null);

    expect(element.hasAttribute('nonce')).toBe(false);
  });

  it('is a no-op when nonce is empty string', () => {
    const element = document.createElement('script');
    applyNonce(element, '');

    expect(element.hasAttribute('nonce')).toBe(false);
  });
});
