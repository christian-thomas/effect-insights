export interface CookiePolicyCookie {
  readonly name: string;
  readonly party: string;
  readonly domainSetOn: string | null;
  readonly purpose: string | null;
  readonly firstSeenUrl: string | null;
}

export interface CookiePolicyCategory {
  readonly category: string;
  readonly label: string;
  readonly description: string;
  readonly cookies: CookiePolicyCookie[];
}

export interface CookiePolicy {
  readonly domain: {
    readonly fqdn: string;
    readonly publicKey: string;
  };
  readonly generatedAt: string;
  readonly scan: {
    readonly id: string;
    readonly finishedAt: string;
  } | null;
  readonly categories: CookiePolicyCategory[];
}
