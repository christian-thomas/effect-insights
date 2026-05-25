import { defineStore } from 'pinia';
import type { ConsentEvent, ConsentEventFilters } from '~/types/consent';
import {
  emptyPagination,
  metaToPagination,
  type PaginatedResponse,
  type Pagination,
} from '~/types/pagination';

interface ConsentEventsState {
  items: ConsentEvent[];
  pagination: Pagination;
  filters: ConsentEventFilters;
  loading: boolean;
  error: Error | null;
}

const toQueryString = (filters: ConsentEventFilters): string => {
  const params = new URLSearchParams();
  if (filters.domainId) params.set('domain_id', filters.domainId);
  if (filters.anonymousId) params.set('anonymous_id', filters.anonymousId);
  if (filters.eventType) params.set('event_type', filters.eventType);
  if (filters.from) params.set('from', filters.from);
  if (filters.to) params.set('to', filters.to);
  if (filters.perPage) params.set('per_page', String(filters.perPage));
  if (filters.page) params.set('page', String(filters.page));
  const query = params.toString();
  return query ? `?${query}` : '';
};

export const useConsentEventsStore = defineStore('consentEvents', {
  state: (): ConsentEventsState => ({
    items: [],
    pagination: emptyPagination(25),
    filters: {},
    loading: false,
    error: null,
  }),

  actions: {
    async list(filters: ConsentEventFilters = {}): Promise<void> {
      this.loading = true;
      this.error = null;
      this.filters = filters;
      try {
        const response = await $api<PaginatedResponse<ConsentEvent>>(
          `/api/consent-events${toQueryString(filters)}`,
        );
        this.items = response.data;
        this.pagination = metaToPagination(response, 25);
      } catch (caught) {
        this.error = caught instanceof Error ? caught : new Error(String(caught));
      } finally {
        this.loading = false;
      }
    },

    reset(): void {
      this.items = [];
      this.pagination = emptyPagination(25);
      this.filters = {};
      this.error = null;
    },
  },
});
