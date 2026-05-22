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

interface SessionPayload {
  user: AuthUser;
  organisations: ReadonlyArray<Organisation>;
  currentOrganisationId: string | null;
}

interface AuthState {
  user: AuthUser | null;
  currentOrganisation: Organisation | null;
  organisations: ReadonlyArray<Organisation>;
}

const findOrganisation = (
  organisations: ReadonlyArray<Organisation>,
  id: string | null,
): Organisation | null => {
  if (!id) return organisations[0] ?? null;
  return organisations.find((organisation) => organisation.id === id) ?? null;
};

export const useAuthStore = defineStore('auth', {
  state: (): AuthState => ({
    user: null,
    currentOrganisation: null,
    organisations: [],
  }),

  getters: {
    isAuthenticated: (state): boolean => state.user !== null,
    // Used as :key on the layout slot wrapper — changing this re-mounts the
    // entire page subtree on org switch, so every page's onMounted refetches.
    organisationCacheKey: (state): string =>
      state.currentOrganisation?.id ?? 'none',
  },

  actions: {
    hydrate(payload: SessionPayload): void {
      this.user = payload.user;
      this.organisations = payload.organisations;
      this.currentOrganisation = findOrganisation(
        payload.organisations,
        payload.currentOrganisationId,
      );
    },

    async login(email: string, password: string): Promise<void> {
      const response = await $api<SessionPayload>('/api/login', {
        method: 'POST',
        body: { email, password },
      });
      this.hydrate(response);
    },

    async fetchSession(): Promise<void> {
      try {
        const response = await $api<SessionPayload>('/api/me');
        this.hydrate(response);
      } catch {
        this.user = null;
        this.organisations = [];
        this.currentOrganisation = null;
      }
    },

    async switchOrganisation(organisationId: string): Promise<void> {
      const response = await $api<SessionPayload>('/api/organisations/switch', {
        method: 'POST',
        body: { organisation_id: organisationId },
      });
      this.hydrate(response);
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
  },
});
