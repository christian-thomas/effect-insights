import { defineStore } from 'pinia';
import type { CookiePolicy } from '~/types/cookiePolicy';

interface ItemResponse {
  data: CookiePolicy;
}

interface PolicySlot {
  policy: CookiePolicy | null;
  loading: boolean;
  error: Error | null;
}

const emptySlot = (): PolicySlot => ({
  policy: null,
  loading: false,
  error: null,
});

interface CookiePoliciesState {
  byDomainId: Record<string, PolicySlot>;
}

export const useCookiePoliciesStore = defineStore('cookiePolicies', {
  state: (): CookiePoliciesState => ({
    byDomainId: {},
  }),

  getters: {
    forDomain:
      (state) =>
      (domainId: string): PolicySlot =>
        state.byDomainId[domainId] ?? emptySlot(),
  },

  actions: {
    ensureSlot(domainId: string): PolicySlot {
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
        const response = await $api<ItemResponse>(`/api/domains/${domainId}/cookie-policy`);
        slot.policy = response.data;
      } catch (caught) {
        slot.error = caught instanceof Error ? caught : new Error(String(caught));
      } finally {
        slot.loading = false;
      }
    },
  },
});
