export interface SupportedLocale {
  readonly code: string;
  readonly label: string;
  readonly emoji: string;
}

/**
 * Locales the editor can author copy for. The shipped MVP covers the languages
 * the v1.0 plan calls out (EN, FR, DE, ES, NL) plus a handful that show up
 * regularly in EU traffic. Extend here as clients ask for them — no other
 * code changes needed.
 */
export const supportedLocales: ReadonlyArray<SupportedLocale> = [
  { code: 'en', label: 'English', emoji: '🇬🇧' },
  { code: 'fr', label: 'Français', emoji: '🇫🇷' },
  { code: 'de', label: 'Deutsch', emoji: '🇩🇪' },
  { code: 'es', label: 'Español', emoji: '🇪🇸' },
  { code: 'nl', label: 'Nederlands', emoji: '🇳🇱' },
  { code: 'it', label: 'Italiano', emoji: '🇮🇹' },
  { code: 'pt', label: 'Português', emoji: '🇵🇹' },
  { code: 'pl', label: 'Polski', emoji: '🇵🇱' },
];

export const localeLabel = (code: string): string => {
  const match = supportedLocales.find((entry) => entry.code === code);
  return match ? `${match.emoji} ${match.label}` : code;
};
