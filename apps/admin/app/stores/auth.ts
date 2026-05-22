import { defineStore } from 'pinia';

export interface AuthUser {
  readonly id: string;
  readonly name: string;
  readonly email: string;
  readonly isSuperuser: boolean;
}

export interface Organisation {
  readonly id: string;
  readonly name: string;
  readonly slug: string;
}

interface AuthState {
  user: AuthUser | null;
  currentOrganisation: Organisation | null;
  organisations: ReadonlyArray<Organisation>;
}

export const useAuthStore = defineStore('auth', {
  state: (): AuthState => ({
    user: null,
    currentOrganisation: null,
    organisations: [],
  }),

  getters: {
    isAuthenticated: (state): boolean => state.user !== null,
  },

  actions: {
    async login(email: string, password: string): Promise<void> {
      const response = await $api<{
        user: AuthUser;
        organisations: ReadonlyArray<Organisation>;
      }>('/api/login', {
        method: 'POST',
        body: { email, password },
      });
      this.user = response.user;
      this.organisations = response.organisations;
      this.currentOrganisation = response.organisations[0] ?? null;
    },

    async fetchSession(): Promise<void> {
      try {
        const response = await $api<{
          user: AuthUser;
          organisations: ReadonlyArray<Organisation>;
        }>('/api/me');
        this.user = response.user;
        this.organisations = response.organisations;
        this.currentOrganisation =
          this.currentOrganisation ?? response.organisations[0] ?? null;
      } catch {
        this.user = null;
        this.organisations = [];
        this.currentOrganisation = null;
      }
    },

    async logout(): Promise<void> {
      try {
        await $api('/api/logout', { method: 'POST' });
      } catch {
        // Even if the server-side session can't be invalidated, always clear
        // local state — the user has asked to sign out.
      }
      this.user = null;
      this.organisations = [];
      this.currentOrganisation = null;
    },

    setCurrentOrganisation(org: Organisation): void {
      this.currentOrganisation = org;
    },
  },
});
