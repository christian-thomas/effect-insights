<script setup lang="ts">
import type { BadgeProps, TableColumn } from '@nuxt/ui';
import type { Cookie, CookieImport } from '~/types/cookies';

type BadgeColor = NonNullable<BadgeProps['color']>;

const cookiesStore = useCookiesStore();
const { cookies, imports, loading } = storeToRefs(cookiesStore);
const auth = useAuthStore();
const toast = useToast();

const tab = ref<'canonical' | 'imports'>('canonical');
const search = ref('');

onMounted(async () => {
  await Promise.all([
    cookiesStore.listCookies(),
    auth.user?.isSuperuser ? cookiesStore.listImports() : Promise.resolve(),
  ]);
});

let searchTimer: ReturnType<typeof setTimeout> | null = null;
watch(search, (value: string): void => {
  if (searchTimer) clearTimeout(searchTimer);
  searchTimer = setTimeout((): void => {
    void cookiesStore.listCookies(value);
  }, 250);
});

const cookieColumns: TableColumn<Cookie>[] = [
  { accessorKey: 'namePattern', header: 'Pattern' },
  { accessorKey: 'vendor', header: 'Vendor' },
  { accessorKey: 'category', header: 'Category' },
  { accessorKey: 'expiryHint', header: 'Expiry' },
  { accessorKey: 'source', header: 'Source' },
];

const importColumns: TableColumn<CookieImport>[] = [
  { accessorKey: 'namePattern', header: 'Pattern' },
  { accessorKey: 'vendor', header: 'Vendor' },
  { accessorKey: 'category', header: 'Category' },
  { accessorKey: 'sourceVersion', header: 'Source version' },
  { id: 'actions', header: '' },
];

const categoryColor: Record<string, BadgeColor> = {
  necessary: 'success',
  preferences: 'info',
  statistics: 'warning',
  marketing: 'error',
};

const promote = async (importId: string): Promise<void> => {
  await cookiesStore.promote(importId);
  toast.add({ title: 'Promoted to canonical', color: 'success' });
};

const reject = async (importId: string): Promise<void> => {
  await cookiesStore.reject(importId);
  toast.add({ title: 'Marked as rejected', color: 'neutral' });
};
</script>

<template>
  <UContainer class="py-6">
    <UPageHeader
      title="Cookie database"
      :description="`Canonical catalogue (${cookies.length}) plus pending OpenCookieDatabase imports awaiting review.`"
    />

    <div class="mt-4 flex gap-2">
      <UButton :variant="tab === 'canonical' ? 'solid' : 'soft'" @click="tab = 'canonical'">
        Canonical ({{ cookies.length }})
      </UButton>
      <UButton
        v-if="auth.user?.isSuperuser"
        :variant="tab === 'imports' ? 'solid' : 'soft'"
        @click="tab = 'imports'"
      >
        Pending imports ({{ imports.length }})
      </UButton>
    </div>

    <div v-if="tab === 'canonical'" class="mt-6">
      <UInput
        v-model="search"
        placeholder="Search by name or vendor…"
        icon="i-lucide-search"
        class="mb-4 w-full max-w-sm"
      />
      <UTable :data="cookies" :columns="cookieColumns" :loading="loading">
        <template #category-cell="{ row }">
          <UBadge :color="categoryColor[row.original.category] ?? 'neutral'" variant="soft">
            {{ row.original.category }}
          </UBadge>
        </template>
        <template #expiryHint-cell="{ row }">
          <span class="text-gray-500">{{ row.original.expiryHint ?? '—' }}</span>
        </template>
      </UTable>
    </div>

    <div v-else class="mt-6">
      <p class="text-sm text-gray-500 mb-4">
        Run <code>php artisan cookies:import --file=path/to/ocd.csv</code> to stage rows. Review
        each row and promote into the canonical DB or reject.
      </p>
      <UTable :data="imports" :columns="importColumns" :loading="loading">
        <template #category-cell="{ row }">
          <UBadge :color="categoryColor[row.original.category] ?? 'neutral'" variant="soft">
            {{ row.original.category }}
          </UBadge>
        </template>
        <template #actions-cell="{ row }">
          <div class="flex gap-2">
            <UButton size="xs" color="success" @click="promote(row.original.id)">Promote</UButton>
            <UButton size="xs" color="neutral" variant="soft" @click="reject(row.original.id)"
              >Reject</UButton
            >
          </div>
        </template>
      </UTable>
    </div>
  </UContainer>
</template>
