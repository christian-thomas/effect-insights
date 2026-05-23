export type CookieCategory = 'necessary' | 'preferences' | 'statistics' | 'marketing';

export type CookieSource = 'manual' | 'opencookiedb';

export type CookieImportStatus = 'pending' | 'promoted' | 'rejected';

export interface Cookie {
  readonly id: string;
  readonly namePattern: string;
  readonly vendor: string;
  readonly vendorDomain: string | null;
  readonly category: CookieCategory;
  readonly purpose: string;
  readonly expiryHint: string | null;
  readonly source: CookieSource;
  readonly sourceVersion: string | null;
  readonly createdAt: string;
}

export interface CookieImport {
  readonly id: string;
  readonly namePattern: string;
  readonly vendor: string;
  readonly vendorDomain: string | null;
  readonly category: CookieCategory;
  readonly purpose: string;
  readonly expiryHint: string | null;
  readonly source: CookieSource;
  readonly sourceVersion: string | null;
  readonly status: CookieImportStatus;
  readonly reviewedAt: string | null;
  readonly promotedCookieId: string | null;
  readonly createdAt: string;
}
