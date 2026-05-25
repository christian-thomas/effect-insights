import { defineStore } from 'pinia';
import type { Cookie, CookieImport, CookieOverride, CookieOverrideInput } from '~/types/cookies';

interface PaginatedResponse<T> {
  data: T[];
  meta?: {
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
  };
}

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
  loading: boolean;
  error: Error | null;
}

export const useCookiesStore = defineStore('cookies', {
  state: (): CookiesState => ({
    cookies: [],
    imports: [],
    overrides: [],
    reviewQueue: [],
    loading: false,
    error: null,
  }),

  actions: {
    async listCookies(search: string = ''): Promise<void> {
      this.loading = true;
      this.error = null;
      try {
        const query = search ? `?search=${encodeURIComponent(search)}` : '';
        const response = await $api<PaginatedResponse<Cookie>>(`/api/cookies${query}`);
        this.cookies = response.data;
      } catch (caught) {
        this.error = caught instanceof Error ? caught : new Error(String(caught));
      } finally {
        this.loading = false;
      }
    },

    async listImports(): Promise<void> {
      this.loading = true;
      this.error = null;
      try {
        const response = await $api<PaginatedResponse<CookieImport>>('/api/cookie-imports');
        this.imports = response.data;
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
      await this.listImports();
      return response.data;
    },

    async listOverrides(): Promise<void> {
      this.loading = true;
      this.error = null;
      try {
        const response = await $api<PaginatedResponse<CookieOverride>>('/api/cookie-overrides');
        this.overrides = response.data;
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
