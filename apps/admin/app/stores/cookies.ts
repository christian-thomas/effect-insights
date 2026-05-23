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

interface CookiesState {
  cookies: Cookie[];
  imports: CookieImport[];
  overrides: CookieOverride[];
  loading: boolean;
  error: Error | null;
}

export const useCookiesStore = defineStore('cookies', {
  state: (): CookiesState => ({
    cookies: [],
    imports: [],
    overrides: [],
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
