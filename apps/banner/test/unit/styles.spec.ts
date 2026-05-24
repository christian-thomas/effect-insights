import { describe, expect, it } from 'vitest';
import { cls, generateStyles } from '../../src/styles';
import type { BannerColors } from '../../src/types';

const colors: BannerColors = {
  background: '#ffffff',
  text: '#111827',
  primary: '#2563eb',
  primaryText: '#ffffff',
  secondary: '#f3f4f6',
  secondaryText: '#111827',
};

describe('styles', () => {
  it('prefixes class names with tracewatch', () => {
    expect(cls('card')).toBe('tracewatch-card');
  });

  it('interpolates the colour palette into the stylesheet', () => {
    const css = generateStyles(colors, 'modal');
    expect(css).toContain('background: #ffffff');
    expect(css).toContain('color: #111827');
    expect(css).toContain('background: #2563eb');
    expect(css).toContain(`background: ${colors.secondary}`);
  });

  it('uses an extreme z-index so banner sits above host overlays', () => {
    expect(generateStyles(colors, 'modal')).toContain('z-index: 2147483647');
  });

  it('declares the .tracewatch-switch on/off styles for category toggles', () => {
    const css = generateStyles(colors, 'modal');
    expect(css).toMatch(/\.tracewatch-switch\[data-on="true"\]/);
  });

  describe('layouts', () => {
    it('modal centers the card with a backdrop tint', () => {
      const css = generateStyles(colors, 'modal');
      expect(css).toContain('rgba(0, 0, 0, 0.45)');
      expect(css).toContain('inset: 0');
    });

    it('bar-top pins the shell to the top edge', () => {
      const css = generateStyles(colors, 'bar-top');
      expect(css).toContain('top: 0');
      expect(css).not.toContain('inset: 0');
    });

    it('bar-bottom pins the shell to the bottom edge', () => {
      const css = generateStyles(colors, 'bar-bottom');
      expect(css).toContain('bottom: 0');
    });

    it('corner places a compact card in the bottom-right', () => {
      const css = generateStyles(colors, 'corner');
      expect(css).toContain('bottom: 16px');
      expect(css).toContain('right: 16px');
      expect(css).toContain('width: 380px');
    });
  });
});
