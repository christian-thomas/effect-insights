import type { CookieCategory } from './banner.js';
import type { GcmUpdate } from './gcm.js';

export type ConsentEventType = 'granted' | 'denied' | 'changed' | 'withdrawn';

export type ConsentCategories = Readonly<Record<CookieCategory, boolean>>;

export interface ConsentEvent {
  readonly id: string;
  readonly domainPublicKey: string;
  readonly bannerConfigVersion: number;
  readonly anonymousId: string;
  readonly categories: ConsentCategories;
  readonly gcmSignals: GcmUpdate;
  readonly countryCode: string | null;
  readonly userAgentHash: string;
  readonly ipHash: string;
  readonly eventType: ConsentEventType;
  readonly occurredAt: string;
  readonly bannerTextHash: string;
}
