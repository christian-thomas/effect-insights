export type FindingParty = 'first' | 'third';

export interface ObservedCookie {
  readonly name: string;
  readonly domain: string | null;
  readonly valuePattern?: string;
  readonly party: FindingParty;
  readonly firstSeenUrl?: string;
}

export interface ObservedAsset {
  readonly name: string;
  readonly party: FindingParty;
  readonly firstSeenUrl?: string;
}

export interface ScannerResult {
  readonly urlsDiscovered: number;
  readonly urlsScanned: number;
  readonly cookies: ReadonlyArray<ObservedCookie>;
  readonly scripts: ReadonlyArray<ObservedAsset>;
  readonly iframes: ReadonlyArray<ObservedAsset>;
  readonly requests: ReadonlyArray<ObservedAsset>;
}

export interface ScannerRequest {
  readonly urls: ReadonlyArray<string>;
  readonly domainFqdn: string;
}
