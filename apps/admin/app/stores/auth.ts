import { defineStore } from 'pinia';
import { credentialToJson, prepareRequestOptions } from '~/composables/webauthn';
import type { AuthUser } from '~/types/auth';
import type { Organisation } from '~/types/organisation';

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
    organisationCacheKey: (state): string => state.currentOrganisation?.id ?? 'none',
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

    /**
     * Run the WebAuthn assertion ceremony end-to-end:
     *
     *   1. POST /api/passkeys/authentication-options → server stashes
     *      the challenge in the session + returns the request options.
     *   2. navigator.credentials.get() prompts the user's authenticator.
     *   3. POST /api/passkeys/authenticate with the assertion JSON.
     *      Server verifies the signature against the session challenge,
     *      logs the user in, regenerates the session, returns the same
     *      payload as /api/login.
     */
    async loginWithPasskey(): Promise<void> {
      if (typeof navigator === 'undefined' || !('credentials' in navigator)) {
        throw new Error('This browser does not support passkeys.');
      }

      const optionsJson = await $api<Record<string, unknown>>(
        '/api/passkeys/authentication-options',
        { method: 'POST' },
      );
      const requestOptions = prepareRequestOptions(optionsJson as never);

      const credential = (await navigator.credentials.get({
        publicKey: requestOptions,
      })) as PublicKeyCredential | null;
      if (credential === null) {
        throw new Error('No passkey selected.');
      }

      const response = await $api<SessionPayload>('/api/passkeys/authenticate', {
        method: 'POST',
        body: { passkey: credentialToJson(credential) },
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

    async updateProfile(payload: { name?: string; email?: string }): Promise<void> {
      const response = await $api<SessionPayload>('/api/me', {
        method: 'PATCH',
        body: payload,
      });
      this.hydrate(response);
    },

    async changePassword(payload: {
      current_password: string;
      password: string;
      password_confirmation: string;
    }): Promise<void> {
      await $api('/api/me/password', {
        method: 'POST',
        body: payload,
      });
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
