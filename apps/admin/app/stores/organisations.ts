import { defineStore } from 'pinia';
import type { Organisation, OrganisationInput } from '~/types/organisation';

interface ListResponse {
  data: Organisation[];
}

interface ItemResponse {
  data: Organisation;
}

interface OrganisationsState {
  items: Organisation[];
  loading: boolean;
  error: Error | null;
}

export const useOrganisationsStore = defineStore('organisations', {
  state: (): OrganisationsState => ({
    items: [],
    loading: false,
    error: null,
  }),

  actions: {
    async list(): Promise<void> {
      this.loading = true;
      this.error = null;
      try {
        const response = await $api<ListResponse>('/api/organisations');
        this.items = response.data;
      } catch (caught) {
        this.error = caught instanceof Error ? caught : new Error(String(caught));
      } finally {
        this.loading = false;
      }
    },

    async create(input: OrganisationInput): Promise<Organisation> {
      const response = await $api<ItemResponse>('/api/organisations', {
        method: 'POST',
        body: input,
      });
      await this.list();
      return response.data;
    },

    async update(id: string, input: Partial<OrganisationInput>): Promise<Organisation> {
      const response = await $api<ItemResponse>(`/api/organisations/${id}`, {
        method: 'PATCH',
        body: input,
      });
      await this.list();
      return response.data;
    },

    async remove(id: string): Promise<void> {
      await $api(`/api/organisations/${id}`, { method: 'DELETE' });
      await this.list();
    },
  },
});
