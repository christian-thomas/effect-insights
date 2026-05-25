import { defineStore } from 'pinia';
import type { CurrentOrganisationInput } from '~/types/currentOrganisation';
import type { Organisation } from '~/types/organisation';

interface CurrentOrganisationState {
  organisation: Organisation | null;
  loading: boolean;
  saving: boolean;
}

/**
 * The active org's runtime settings, fetched from /api/current-organisation.
 *
 * Distinct from the auth store's `currentOrganisation` because that one
 * carries the lightweight shape used for sidebar display + org switching,
 * which can lag behind a settings edit. After a successful PATCH the auth
 * store is also re-hydrated so the sidebar org switcher reflects the
 * fresh values.
 */
export const useCurrentOrganisationStore = defineStore('currentOrganisation', {
  state: (): CurrentOrganisationState => ({
    organisation: null,
    loading: false,
    saving: false,
  }),

  actions: {
    async fetch(): Promise<void> {
      this.loading = true;
      try {
        const response = await $api<{ data: Organisation }>('/api/current-organisation');
        this.organisation = response.data;
      } finally {
        this.loading = false;
      }
    },

    async update(payload: CurrentOrganisationInput): Promise<void> {
      this.saving = true;
      try {
        const response = await $api<{ data: Organisation }>('/api/current-organisation', {
          method: 'PATCH',
          body: payload,
        });
        this.organisation = response.data;
        // Keep the auth store in lockstep so the sidebar / dashboard /
        // anything reading the active org sees the new values without a
        // page reload.
        await useAuthStore().fetchSession();
      } finally {
        this.saving = false;
      }
    },
  },
});
