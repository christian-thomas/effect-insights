import { defineStore } from 'pinia';
import { credentialToJson, prepareCreateOptions } from '~/composables/webauthn';
import type { Passkey } from '~/types/passkey';

interface ListResponse {
  data: Passkey[];
}

interface ItemResponse {
  data: Passkey;
}

interface State {
  items: Passkey[];
  loading: boolean;
  registering: boolean;
  error: Error | null;
}

/**
 * Manage the current user's registered passkeys. Distinct from the
 * authentication ceremony (lives on the auth store) — this is the
 * "Settings → Passkeys" page surface.
 */
export const usePasskeysStore = defineStore('passkeys', {
  state: (): State => ({
    items: [],
    loading: false,
    registering: false,
    error: null,
  }),

  actions: {
    async list(): Promise<void> {
      this.loading = true;
      this.error = null;
      try {
        const response = await $api<ListResponse>('/api/passkeys');
        this.items = response.data;
      } catch (caught) {
        this.error = caught instanceof Error ? caught : new Error(String(caught));
      } finally {
        this.loading = false;
      }
    },

    /**
     * Run the WebAuthn registration ceremony:
     *
     *   1. POST /api/passkeys/register-options → server stores the
     *      challenge in the session + returns the creation options.
     *   2. navigator.credentials.create() prompts the user's
     *      authenticator (TouchID, Windows Hello, YubiKey).
     *   3. POST /api/passkeys with the attestation + the user-provided
     *      label. Server validates the response against the session
     *      challenge and persists.
     */
    async register(name: string): Promise<Passkey> {
      if (typeof navigator === 'undefined' || !('credentials' in navigator)) {
        throw new Error('This browser does not support passkeys.');
      }

      this.registering = true;
      try {
        const optionsJson = await $api<Record<string, unknown>>('/api/passkeys/register-options', {
          method: 'POST',
        });
        const createOptions = prepareCreateOptions(optionsJson as never);

        const credential = (await navigator.credentials.create({
          publicKey: createOptions,
        })) as PublicKeyCredential | null;
        if (credential === null) {
          throw new Error('Registration cancelled.');
        }

        const response = await $api<ItemResponse>('/api/passkeys', {
          method: 'POST',
          body: {
            passkey: credentialToJson(credential),
            name,
          },
        });
        await this.list();
        return response.data;
      } finally {
        this.registering = false;
      }
    },

    async remove(id: string): Promise<void> {
      await $api(`/api/passkeys/${id}`, { method: 'DELETE' });
      await this.list();
    },
  },
});
