import { defineStore } from 'pinia';
import type { Cookie, CookieImport } from '~/types/cookies';

interface PaginatedResponse<T> {
  data: T[];
  meta: {
    total: number;
    per_page: number;
    current_page: number;
    last_page: number;
  };
}

interface CookiesState {
  cookies: Cookie[];
  imports: CookieImport[];
  loading: boolean;
  error: Error | null;
}

export const useCookiesStore = defineStore('cookies', {
  state: (): CookiesState => ({
    cookies: [],
    imports: [],
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

    async reject(importId: string): Promise<void> {
      await $api(`/api/cookie-imports/${importId}/reject`, { method: 'POST' });
      await this.listImports();
    },
  },
});
