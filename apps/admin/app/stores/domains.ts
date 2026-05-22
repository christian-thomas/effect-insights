import { defineStore } from 'pinia';
import type { Domain, DomainInput } from '@effect-cmp/types/domain';

interface DomainListResponse {
  data: Domain[];
}

interface DomainItemResponse {
  data: Domain;
}

interface DomainsState {
  items: Domain[];
  loading: boolean;
  error: Error | null;
}

export const useDomainsStore = defineStore('domains', {
  state: (): DomainsState => ({
    items: [],
    loading: false,
    error: null,
  }),

  getters: {
    findById: (state) => (id: string): Domain | null =>
      state.items.find((domain) => domain.id === id) ?? null,
  },

  actions: {
    async list(): Promise<void> {
      this.loading = true;
      this.error = null;
      try {
        const response = await $api<DomainListResponse>('/api/domains');
        this.items = response.data;
      } catch (caught) {
        this.error =
          caught instanceof Error ? caught : new Error(String(caught));
      } finally {
        this.loading = false;
      }
    },

    async fetchOne(id: string): Promise<Domain> {
      const response = await $api<DomainItemResponse>(`/api/domains/${id}`);
      // Keep the list cache fresh so list-driven views stay consistent.
      const index = this.items.findIndex((domain) => domain.id === id);
      if (index >= 0) {
        this.items.splice(index, 1, response.data);
      } else {
        this.items.push(response.data);
      }
      return response.data;
    },

    async create(input: DomainInput): Promise<Domain> {
      const response = await $api<DomainItemResponse>('/api/domains', {
        method: 'POST',
        body: input,
      });
      await this.list();
      return response.data;
    },

    async update(id: string, input: Partial<DomainInput>): Promise<Domain> {
      const response = await $api<DomainItemResponse>(`/api/domains/${id}`, {
        method: 'PATCH',
        body: input,
      });
      await this.list();
      return response.data;
    },

    async remove(id: string): Promise<void> {
      await $api(`/api/domains/${id}`, { method: 'DELETE' });
      await this.list();
    },

    reset(): void {
      this.items = [];
      this.loading = false;
      this.error = null;
    },
  },
});
