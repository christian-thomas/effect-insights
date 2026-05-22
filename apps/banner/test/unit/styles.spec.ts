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
  it('prefixes class names with effectcmp', () => {
    expect(cls('card')).toBe('effectcmp-card');
  });

  it('interpolates the colour palette into the stylesheet', () => {
    const css = generateStyles(colors);
    expect(css).toContain('background: #ffffff');
    expect(css).toContain('color: #111827');
    expect(css).toContain('background: #2563eb');
    expect(css).toContain(`background: ${colors.secondary}`);
  });

  it('uses an extreme z-index so banner sits above host overlays', () => {
    expect(generateStyles(colors)).toContain('z-index: 2147483647');
  });

  it('declares the .effectcmp-switch on/off styles for category toggles', () => {
    const css = generateStyles(colors);
    expect(css).toMatch(/\.effectcmp-switch\[data-on="true"\]/);
  });
});
