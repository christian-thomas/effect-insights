<script setup lang="ts">
import type { TableColumn } from '@nuxt/ui';
import type { Passkey } from '~/types/passkey';

const store = usePasskeysStore();
const { items: passkeys, loading, registering } = storeToRefs(store);
const toast = useToast();

await store.list();

const supported = computed((): boolean => {
  if (typeof navigator === 'undefined') return false;
  return 'credentials' in navigator && typeof PublicKeyCredential !== 'undefined';
});

const columns: TableColumn<Passkey>[] = [
  { accessorKey: 'name', header: 'Label' },
  { accessorKey: 'createdAt', header: 'Registered' },
  { accessorKey: 'lastUsedAt', header: 'Last used' },
  { id: 'actions', header: '' },
];

const formatTime = (iso: string | null): string => {
  if (!iso) return 'Never';
  return new Date(iso).toLocaleString();
};

// Register modal — replaces window.prompt() with a designed input.
const registerOpen = ref(false);
const registerLabel = ref('');
const registerError = ref<string | null>(null);

const openRegister = (): void => {
  registerLabel.value = '';
  registerError.value = null;
  registerOpen.value = true;
};

const submitRegister = async (close: () => void): Promise<void> => {
  const trimmed = registerLabel.value.trim();
  if (trimmed === '') {
    registerError.value = 'Give the passkey a label so you can recognise it later.';
    return;
  }
  registerError.value = null;

  try {
    await store.register(trimmed);
    toast.add({
      title: `Passkey "${trimmed}" registered`,
      color: 'success',
      icon: 'i-lucide-fingerprint',
    });
    close();
  } catch (caught) {
    if (caught instanceof DOMException && caught.name === 'NotAllowedError') {
      // User cancelled the browser prompt — leave the modal open so they can retry.
      return;
    }
    toast.add({
      title: 'Could not register passkey',
      description: caught instanceof Error ? caught.message : 'Please try again',
      color: 'error',
      icon: 'i-lucide-triangle-alert',
    });
  }
};

// Revoke modal — replaces window.confirm() with a designed destructive confirm.
const revokeOpen = ref(false);
const revokeTarget = ref<Passkey | null>(null);
const revoking = ref(false);

const openRevoke = (passkey: Passkey): void => {
  revokeTarget.value = passkey;
  revokeOpen.value = true;
};

const submitRevoke = async (close: () => void): Promise<void> => {
  const target = revokeTarget.value;
  if (target === null) {
    close();
    return;
  }
  revoking.value = true;
  try {
    await store.remove(target.id);
    toast.add({
      title: `Removed "${target.name}"`,
      color: 'success',
      icon: 'i-lucide-check',
    });
    close();
  } catch (caught) {
    toast.add({
      title: 'Could not revoke',
      description: caught instanceof Error ? caught.message : 'Please try again',
      color: 'error',
      icon: 'i-lucide-triangle-alert',
    });
  } finally {
    revoking.value = false;
  }
};
</script>

<template>
  <UDashboardNavbar title="Passkeys">
    <template #right>
      <UButton
        v-if="supported"
        icon="i-lucide-fingerprint"
        :loading="registering"
        label="Register a passkey"
        @click="openRegister"
      /></template>
  </UDashboardNavbar>

  <div class="p-4 sm:p-6 lg:p-8 space-y-4">
    <UAlert
      v-if="!supported"
      color="warning"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      title="This browser doesn't support passkeys"
      description="Use a recent version of Chrome, Safari, Edge, or Firefox to register or sign in with a passkey. Your password still works regardless."
    />

    <UCard>
      <p class="text-sm text-gray-500">
        Passkeys let you sign in without a password using TouchID, Windows Hello, a YubiKey, or any
        other WebAuthn-compatible authenticator. Register one or more — they're a fallback for each
        other. Email + password stays available as a recovery path.
      </p>
    </UCard>

    <UCard v-if="!loading && passkeys.length === 0">
      <div class="flex flex-col items-center gap-2 py-12 text-center">
        <UIcon name="i-lucide-fingerprint" class="text-4xl text-gray-400" />
        <h2 class="text-lg font-semibold">No passkeys registered</h2>
        <p class="text-sm text-gray-500 max-w-md">
          Click "Register a passkey" to add one. The browser will prompt your authenticator
          (TouchID, Windows Hello, YubiKey, etc.) and you'll be able to sign in with it from then
          on.
        </p>
        <UButton
          v-if="supported"
          class="mt-2"
          icon="i-lucide-fingerprint"
          label="Register a passkey"
          :loading="registering"
          @click="openRegister"
        />
      </div>
    </UCard>

    <UTable v-else :data="passkeys" :columns="columns" :loading="loading" class="w-full">
      <template #name-cell="{ row }">
        <span class="text-sm font-medium">{{ row.original.name }}</span>
      </template>
      <template #createdAt-cell="{ row }">
        <span class="text-xs text-gray-500">{{ formatTime(row.original.createdAt) }}</span>
      </template>
      <template #lastUsedAt-cell="{ row }">
        <span
          class="text-xs"
          :class="row.original.lastUsedAt ? 'text-gray-700 dark:text-gray-300' : 'text-gray-400'"
        >
          {{ formatTime(row.original.lastUsedAt) }}
        </span>
      </template>
      <template #actions-cell="{ row }">
        <UButton
          size="xs"
          variant="ghost"
          color="error"
          icon="i-lucide-trash-2"
          label="Revoke"
          @click="openRevoke(row.original)"
        />
      </template>
    </UTable>
  </div>

  <UModal
    v-model:open="registerOpen"
    title="Register a passkey"
    description="Label this passkey so you can recognise it later — your browser will prompt your authenticator next."
  >
    <template #body>
      <UFormField
        label="Label"
        name="label"
        :error="registerError ?? undefined"
        help="A short name to identify this authenticator."
      >
        <UInput
          v-model="registerLabel"
          placeholder="e.g. MacBook TouchID"
          autocomplete="off"
          autofocus
          class="w-full"
          @keydown.enter.prevent="submitRegister(() => (registerOpen = false))"
        />
      </UFormField>
    </template>
    <template #footer="{ close }">
      <div class="flex justify-end gap-2 w-full">
        <UButton color="neutral" variant="ghost" label="Cancel" @click="close" />
        <UButton
          icon="i-lucide-fingerprint"
          label="Register"
          :loading="registering"
          @click="submitRegister(close)"
        />
      </div>
    </template>
  </UModal>

  <UModal
    v-model:open="revokeOpen"
    :title="revokeTarget ? `Revoke “${revokeTarget.name}”?` : 'Revoke passkey?'"
    description="Your other registered passkeys (and your email + password) will still work."
  >
    <template #footer="{ close }">
      <div class="flex justify-end gap-2 w-full">
        <UButton color="neutral" variant="ghost" label="Keep" @click="close" />
        <UButton
          color="error"
          icon="i-lucide-trash-2"
          label="Revoke"
          :loading="revoking"
          @click="submitRevoke(close)"
        />
      </div>
    </template>
  </UModal>
</template>
