import type { BannerCopy } from '@effect-cmp/types/schemas/banner';

/**
 * Fallback copy seeded into a new locale tab when the editor adds it. The
 * user is expected to translate these strings before publishing. Source is
 * English for now; iteration 5+ could pull translations from a managed
 * dictionary (Deepl / Lokalise / an Effect-curated JSON).
 */
export const defaultCopyForLocale = (): BannerCopy => ({
  title: 'We use cookies',
  body: 'We use cookies to make the site work, understand how it\'s used, and to personalise content. Choose which cookies you accept.',
  acceptAllLabel: 'Accept all',
  rejectAllLabel: 'Reject all',
  customiseLabel: 'Customise',
  savePreferencesLabel: 'Save preferences',
  categoryDescriptions: {
    necessary: 'Required for the site to function. Cannot be disabled.',
    preferences: 'Remember choices you make (language, region, etc).',
    statistics: 'Anonymous analytics about how the site is used.',
    marketing: 'Personalised advertising and tracking.',
  },
});
