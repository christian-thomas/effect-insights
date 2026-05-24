import type { CookieCategory } from '@effectinsight/types/banner';

export interface CanonicalCookie {
  readonly namePattern: string;
  readonly vendor: string;
  readonly vendorDomain: string | null;
  readonly category: CookieCategory;
  readonly purpose: string;
  readonly expiryHint: string | null;
  readonly source: 'open_cookie_db' | 'internal';
  readonly sourceVersion: string;
}

export type CookieDbSnapshot = ReadonlyArray<CanonicalCookie>;
