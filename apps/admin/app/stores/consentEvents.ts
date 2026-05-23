import { defineStore } from 'pinia';
import type { ConsentEvent, ConsentEventFilters } from '~/types/consent';

interface Pagination {
  total: number;
  perPage: number;
  currentPage: number;
  lastPage: number;
}

interface ListResponse {
  data: ConsentEvent[];
  meta: {
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
  };
}

interface ConsentEventsState {
  items: ConsentEvent[];
  pagination: Pagination;
  filters: ConsentEventFilters;
  loading: boolean;
  error: Error | null;
}

const emptyPagination = (): Pagination => ({
  total: 0,
  perPage: 25,
  currentPage: 1,
  lastPage: 1,
});

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
    pagination: emptyPagination(),
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
        const response = await $api<ListResponse>(`/api/consent-events${toQueryString(filters)}`);
        this.items = response.data;
        this.pagination = {
          total: response.meta.total,
          perPage: response.meta.per_page,
          currentPage: response.meta.current_page,
          lastPage: response.meta.last_page,
        };
      } catch (caught) {
        this.error = caught instanceof Error ? caught : new Error(String(caught));
      } finally {
        this.loading = false;
      }
    },

    reset(): void {
      this.items = [];
      this.pagination = emptyPagination();
      this.filters = {};
      this.error = null;
    },
  },
});
