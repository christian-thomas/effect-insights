import { defineStore } from 'pinia';
import type {
  AccessibilityRuleOverride,
  AccessibilityRuleOverrideInput,
} from '~/types/accessibilityRuleOverride';

interface ListResponse {
  data: AccessibilityRuleOverride[];
}

interface ItemResponse {
  data: AccessibilityRuleOverride;
}

interface State {
  items: AccessibilityRuleOverride[];
  loading: boolean;
  error: Error | null;
}

export const useAccessibilityRuleOverridesStore = defineStore('accessibilityRuleOverrides', {
  state: (): State => ({
    items: [],
    loading: false,
    error: null,
  }),

  actions: {
    async list(): Promise<void> {
      this.loading = true;
      this.error = null;
      try {
        const response = await $api<ListResponse>('/api/accessibility-rule-overrides');
        this.items = response.data;
      } catch (caught) {
        this.error = caught instanceof Error ? caught : new Error(String(caught));
      } finally {
        this.loading = false;
      }
    },

    async create(input: AccessibilityRuleOverrideInput): Promise<AccessibilityRuleOverride> {
      const response = await $api<ItemResponse>('/api/accessibility-rule-overrides', {
        method: 'POST',
        body: input,
      });
      await this.list();
      return response.data;
    },

    async remove(id: string): Promise<void> {
      await $api(`/api/accessibility-rule-overrides/${id}`, { method: 'DELETE' });
      await this.list();
    },
  },
});
