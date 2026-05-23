import { defineStore } from 'pinia';
import type { BannerDraftInput } from '@effect-cmp/types/schemas/banner';
import type { BannerConfig } from '~/types/banner';

interface FetchResponse {
  draft: { data: BannerConfig };
  published: { data: BannerConfig } | null;
}

interface ItemResponse {
  data: BannerConfig;
}

interface BannerSlot {
  draft: BannerConfig | null;
  published: BannerConfig | null;
  loading: boolean;
  error: Error | null;
}

const emptySlot = (): BannerSlot => ({
  draft: null,
  published: null,
  loading: false,
  error: null,
});

interface BannerConfigsState {
  byDomainId: Record<string, BannerSlot>;
}

export const useBannerConfigsStore = defineStore('bannerConfigs', {
  state: (): BannerConfigsState => ({
    byDomainId: {},
  }),

  getters: {
    forDomain:
      (state) =>
      (domainId: string): BannerSlot =>
        state.byDomainId[domainId] ?? emptySlot(),
  },

  actions: {
    ensureSlot(domainId: string): BannerSlot {
      if (!this.byDomainId[domainId]) {
        this.byDomainId[domainId] = emptySlot();
      }
      return this.byDomainId[domainId]!;
    },

    async fetch(domainId: string): Promise<void> {
      const slot = this.ensureSlot(domainId);
      slot.loading = true;
      slot.error = null;
      try {
        const response = await $api<FetchResponse>(`/api/domains/${domainId}/banner-config`);
        slot.draft = response.draft.data;
        slot.published = response.published?.data ?? null;
      } catch (caught) {
        slot.error = caught instanceof Error ? caught : new Error(String(caught));
      } finally {
        slot.loading = false;
      }
    },

    async saveDraft(domainId: string, input: BannerDraftInput): Promise<BannerConfig> {
      const slot = this.ensureSlot(domainId);
      const response = await $api<ItemResponse>(`/api/domains/${domainId}/banner-config/draft`, {
        method: 'PUT',
        body: input,
      });
      slot.draft = response.data;
      return response.data;
    },

    async publish(domainId: string): Promise<BannerConfig> {
      const response = await $api<ItemResponse>(`/api/domains/${domainId}/banner-config/publish`, {
        method: 'POST',
      });
      // Refresh both draft + published from the server canonical state.
      await this.fetch(domainId);
      return response.data;
    },
  },
});
