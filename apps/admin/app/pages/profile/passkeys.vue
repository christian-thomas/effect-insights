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

const onRegister = async (): Promise<void> => {
  const name = window.prompt(
    'Label this passkey (e.g. "MacBook TouchID", "YubiKey").',
    '',
  );
  if (name === null || name.trim() === '') return;

  try {
    await store.register(name.trim());
    toast.add({
      title: `Passkey "${name}" registered`,
      color: 'success',
      icon: 'i-lucide-fingerprint',
    });
  } catch (caught) {
    if (caught instanceof DOMException && caught.name === 'NotAllowedError') {
      return; // User cancelled the browser prompt.
    }
    toast.add({
      title: 'Could not register passkey',
      description: caught instanceof Error ? caught.message : 'Please try again',
      color: 'error',
      icon: 'i-lucide-triangle-alert',
    });
  }
};

const onRemove = async (passkey: Passkey): Promise<void> => {
  if (
    !window.confirm(
      `Revoke "${passkey.name}"? Your other registered passkeys (and your email + password) will still work.`,
    )
  ) {
    return;
  }
  try {
    await store.remove(passkey.id);
    toast.add({
      title: `Removed "${passkey.name}"`,
      color: 'success',
      icon: 'i-lucide-check',
    });
  } catch (caught) {
    toast.add({
      title: 'Could not revoke',
      description: caught instanceof Error ? caught.message : 'Please try again',
      color: 'error',
      icon: 'i-lucide-triangle-alert',
    });
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
        @click="onRegister"
      />
    </template>
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
          @click="onRegister"
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
        <span class="text-xs" :class="row.original.lastUsedAt ? 'text-gray-700 dark:text-gray-300' : 'text-gray-400'">
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
          @click="onRemove(row.original)"
        />
      </template>
    </UTable>
  </div>
</template>
