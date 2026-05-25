import { defineStore } from 'pinia';
import type { Cookie, CookieImport, CookieOverride, CookieOverrideInput } from '~/types/cookies';
import {
  emptyPagination,
  metaToPagination,
  type PaginatedResponse,
  type Pagination,
} from '~/types/pagination';

interface ItemResponse<T> {
  data: T;
}

export interface ReviewQueueItem {
  name: string;
  occurrences: number;
  scanCount: number;
  firstSeenUrl: string | null;
  firstSeenAt: string | null;
  lastSeenAt: string | null;
}

interface CookiesState {
  cookies: Cookie[];
  imports: CookieImport[];
  overrides: CookieOverride[];
  reviewQueue: ReviewQueueItem[];
  cookiesPagination: Pagination;
  importsPagination: Pagination;
  overridesPagination: Pagination;
  cookiesSearch: string;
  cookiesPage: number;
  importsPage: number;
  overridesPage: number;
  loading: boolean;
  error: Error | null;
}

const PER_PAGE = 50;

const buildQuery = (params: Record<string, string | number | null | undefined>): string => {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === null || value === undefined || value === '') continue;
    search.set(key, String(value));
  }
  const query = search.toString();
  return query ? `?${query}` : '';
};

export const useCookiesStore = defineStore('cookies', {
  state: (): CookiesState => ({
    cookies: [],
    imports: [],
    overrides: [],
    reviewQueue: [],
    cookiesPagination: emptyPagination(PER_PAGE),
    importsPagination: emptyPagination(PER_PAGE),
    overridesPagination: emptyPagination(PER_PAGE),
    cookiesSearch: '',
    cookiesPage: 1,
    importsPage: 1,
    overridesPage: 1,
    loading: false,
    error: null,
  }),

  actions: {
    async listCookies(search?: string, page?: number): Promise<void> {
      if (search !== undefined && search !== this.cookiesSearch) {
        this.cookiesSearch = search;
        this.cookiesPage = 1;
      }
      if (page !== undefined) {
        this.cookiesPage = page;
      }
      this.loading = true;
      this.error = null;
      try {
        const query = buildQuery({ search: this.cookiesSearch, page: this.cookiesPage });
        const response = await $api<PaginatedResponse<Cookie>>(`/api/cookies${query}`);
        this.cookies = response.data;
        this.cookiesPagination = metaToPagination(response, PER_PAGE);
      } catch (caught) {
        this.error = caught instanceof Error ? caught : new Error(String(caught));
      } finally {
        this.loading = false;
      }
    },

    async listImports(page?: number): Promise<void> {
      if (page !== undefined) {
        this.importsPage = page;
      }
      this.loading = true;
      this.error = null;
      try {
        const query = buildQuery({ page: this.importsPage });
        const response = await $api<PaginatedResponse<CookieImport>>(`/api/cookie-imports${query}`);
        this.imports = response.data;
        this.importsPagination = metaToPagination(response, PER_PAGE);
      } catch (caught) {
        this.error = caught instanceof Error ? caught : new Error(String(caught));
      } finally {
        this.loading = false;
      }
    },

    async promote(importId: string): Promise<void> {
      await $api(`/api/cookie-imports/${importId}/promote`, { method: 'POST' });
      await Promise.all([this.listImports(), this.listCookies()]);
    },

    async promoteAll(): Promise<{ promoted: number; failed: number; firstError: string | null }> {
      const response = await $api<{
        data: { promoted: number; failed: number; firstError: string | null };
      }>('/api/cookie-imports/promote-all', { method: 'POST' });
      await Promise.all([this.listImports(), this.listCookies()]);
      return response.data;
    },

    async reject(importId: string): Promise<void> {
      await $api(`/api/cookie-imports/${importId}/reject`, { method: 'POST' });
      await this.listImports();
    },

    async uploadImport(
      file: File,
      sourceVersion: string | null,
    ): Promise<{ created: number; existing: number; skipped: number; sourceVersion: string }> {
      const formData = new FormData();
      formData.append('file', file);
      if (sourceVersion && sourceVersion.trim() !== '') {
        formData.append('source_version', sourceVersion.trim());
      }
      const response = await $api<{
        data: { created: number; existing: number; skipped: number; sourceVersion: string };
      }>('/api/cookie-imports', { method: 'POST', body: formData });
      this.importsPage = 1;
      await this.listImports();
      return response.data;
    },

    async listOverrides(page?: number): Promise<void> {
      if (page !== undefined) {
        this.overridesPage = page;
      }
      this.loading = true;
      this.error = null;
      try {
        const query = buildQuery({ page: this.overridesPage });
        const response = await $api<PaginatedResponse<CookieOverride>>(
          `/api/cookie-overrides${query}`,
        );
        this.overrides = response.data;
        this.overridesPagination = metaToPagination(response, PER_PAGE);
      } catch (caught) {
        this.error = caught instanceof Error ? caught : new Error(String(caught));
      } finally {
        this.loading = false;
      }
    },

    async listReviewQueue(): Promise<void> {
      this.loading = true;
      this.error = null;
      try {
        const response = await $api<{ data: ReviewQueueItem[] }>('/api/scan-findings/review');
        this.reviewQueue = response.data;
      } catch (caught) {
        this.error = caught instanceof Error ? caught : new Error(String(caught));
      } finally {
        this.loading = false;
      }
    },

    async createOverride(input: CookieOverrideInput): Promise<CookieOverride> {
      const response = await $api<ItemResponse<CookieOverride>>('/api/cookie-overrides', {
        method: 'POST',
        body: input,
      });
      await this.listOverrides();
      return response.data;
    },

    async updateOverride(id: string, input: Partial<CookieOverrideInput>): Promise<CookieOverride> {
      const response = await $api<ItemResponse<CookieOverride>>(`/api/cookie-overrides/${id}`, {
        method: 'PATCH',
        body: input,
      });
      await this.listOverrides();
      return response.data;
    },

    async deleteOverride(id: string): Promise<void> {
      await $api(`/api/cookie-overrides/${id}`, { method: 'DELETE' });
      await this.listOverrides();
    },
  },
});
