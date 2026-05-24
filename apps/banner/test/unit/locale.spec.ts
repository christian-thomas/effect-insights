import { describe, expect, it } from 'vitest';
import { negotiateLocale } from '../../src/locale';

describe('negotiateLocale', () => {
  it('picks exact region+language match when available', () => {
    expect(
      negotiateLocale({
        enabled: ['en', 'en-GB', 'fr'],
        fallback: 'en',
        browserLanguages: ['en-GB', 'en', 'fr'],
      }),
    ).toBe('en-GB');
  });

  it('falls back to primary subtag match when no exact tag exists', () => {
    expect(
      negotiateLocale({
        enabled: ['en', 'fr'],
        fallback: 'en',
        browserLanguages: ['en-US', 'en'],
      }),
    ).toBe('en');
  });

  it('matches the browser primary subtag against an enabled regional locale', () => {
    expect(
      negotiateLocale({
        enabled: ['en-GB', 'fr'],
        fallback: 'en-GB',
        browserLanguages: ['en-US', 'en-CA'],
      }),
    ).toBe('en-GB');
  });

  it('walks the browser list in preference order', () => {
    expect(
      negotiateLocale({
        enabled: ['en', 'fr', 'de'],
        fallback: 'en',
        browserLanguages: ['ja', 'fr-FR', 'en-US'],
      }),
    ).toBe('fr');
  });

  it('returns the fallback when no browser language matches', () => {
    expect(
      negotiateLocale({
        enabled: ['en', 'fr'],
        fallback: 'en',
        browserLanguages: ['ja', 'zh'],
      }),
    ).toBe('en');
  });

  it('returns the fallback when the browser list is empty', () => {
    expect(
      negotiateLocale({
        enabled: ['en', 'fr'],
        fallback: 'fr',
        browserLanguages: [],
      }),
    ).toBe('fr');
  });

  it('is case-insensitive on the browser side', () => {
    expect(
      negotiateLocale({
        enabled: ['en-GB'],
        fallback: 'en-GB',
        browserLanguages: ['EN-gb'],
      }),
    ).toBe('en-GB');
  });

  it('returns the fallback when no locales are enabled', () => {
    expect(
      negotiateLocale({
        enabled: [],
        fallback: 'en',
        browserLanguages: ['fr'],
      }),
    ).toBe('en');
  });
});
