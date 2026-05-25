import { defineStore } from 'pinia';
import type { BannerDraftInput } from '@effectinsight/types/schemas/banner';
import type { BannerConfig } from '~/types/banner';

interface FetchResponse {
  draft: { data: BannerConfig };
  published: { data: BannerConfig } | null;
}

interface ItemResponse {
  data: BannerConfig;
}

export interface BannerConfigChange {
  field: string;
  category: 'material' | 'cosmetic';
  previous: unknown;
  next: unknown;
  reason: string;
}

export interface BannerConfigDiff {
  hasChanges: boolean;
  isMaterial: boolean;
  isFirstPublish: boolean;
  changes: BannerConfigChange[];
}

interface DiffResponse {
  data: BannerConfigDiff;
}

interface ListResponse {
  data: BannerConfig[];
}

interface BannerSlot {
  draft: BannerConfig | null;
  published: BannerConfig | null;
  diff: BannerConfigDiff | null;
  history: BannerConfig[];
  historyLoading: boolean;
  loading: boolean;
  error: Error | null;
}

const emptySlot = (): BannerSlot => ({
  draft: null,
  published: null,
  diff: null,
  history: [],
  historyLoading: false,
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
        await this.fetchDiff(domainId);
      } catch (caught) {
        slot.error = caught instanceof Error ? caught : new Error(String(caught));
      } finally {
        slot.loading = false;
      }
    },

    async fetchDiff(domainId: string): Promise<void> {
      const slot = this.ensureSlot(domainId);
      try {
        const response = await $api<DiffResponse>(`/api/domains/${domainId}/banner-config/diff`);
        slot.diff = response.data;
      } catch {
        // Diff is non-critical — don't blow up the editor if it 500s.
        slot.diff = null;
      }
    },

    async saveDraft(domainId: string, input: BannerDraftInput): Promise<BannerConfig> {
      const slot = this.ensureSlot(domainId);
      const response = await $api<ItemResponse>(`/api/domains/${domainId}/banner-config/draft`, {
        method: 'PUT',
        body: input,
      });
      slot.draft = response.data;
      // Pending-changes panel needs to reflect the just-saved draft.
      await this.fetchDiff(domainId);
      return response.data;
    },

    async publish(domainId: string): Promise<BannerConfig> {
      const response = await $api<ItemResponse>(`/api/domains/${domainId}/banner-config/publish`, {
        method: 'POST',
      });
      // Refresh both draft + published from the server canonical state.
      // History gains a new row on every publish — refetch alongside.
      await this.fetch(domainId);
      await this.fetchHistory(domainId);
      return response.data;
    },

    async fetchHistory(domainId: string): Promise<void> {
      const slot = this.ensureSlot(domainId);
      slot.historyLoading = true;
      try {
        const response = await $api<ListResponse>(`/api/domains/${domainId}/banner-config/history`);
        slot.history = response.data;
      } finally {
        slot.historyLoading = false;
      }
    },

    async rollback(domainId: string, version: number): Promise<BannerConfig> {
      const response = await $api<ItemResponse>(`/api/domains/${domainId}/banner-config/rollback`, {
        method: 'POST',
        body: { version },
      });
      const slot = this.ensureSlot(domainId);
      // The endpoint returns the new draft — slot it in and refresh the
      // diff so the pending-changes panel reflects the rollback.
      slot.draft = response.data;
      await this.fetchDiff(domainId);
      return response.data;
    },
  },
});
