export type ConsentEventType = 'granted' | 'denied' | 'changed' | 'withdrawn';

export interface ConsentEventCategories {
  necessary: boolean;
  preferences: boolean;
  statistics: boolean;
  marketing: boolean;
}

export interface ConsentEvent {
  id: string;
  domainId: string;
  bannerConfigVersion: number;
  anonymousId: string;
  categories: ConsentEventCategories;
  gcmSignals: Record<string, 'granted' | 'denied'>;
  countryCode: string | null;
  userAgentHash: string;
  ipHash: string;
  eventType: ConsentEventType;
  occurredAt: string;
  bannerTextHash: string;
  createdAt: string;
}

export interface ConsentEventFilters {
  domainId?: string;
  anonymousId?: string;
  eventType?: ConsentEventType;
  from?: string;
  to?: string;
  perPage?: number;
  page?: number;
}
