<script setup lang="ts">
import { z } from 'zod';
import type { BadgeProps, TableColumn } from '@nuxt/ui';
import type {
  Cookie,
  CookieCategory,
  CookieImport,
  CookieOverride,
  CookieOverrideInput,
} from '~/types/cookies';

type BadgeColor = NonNullable<BadgeProps['color']>;

const cookiesStore = useCookiesStore();
const domainsStore = useDomainsStore();
const { cookies, imports, overrides, loading } = storeToRefs(cookiesStore);
const auth = useAuthStore();
const toast = useToast();

type Tab = 'canonical' | 'imports' | 'overrides';
const tab = ref<Tab>('canonical');
const search = ref('');

onMounted(async () => {
  await Promise.all([
    cookiesStore.listCookies(),
    cookiesStore.listOverrides(),
    domainsStore.list(),
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

const overrideColumns: TableColumn<CookieOverride>[] = [
  { accessorKey: 'namePattern', header: 'Pattern' },
  { accessorKey: 'category', header: 'Category' },
  { accessorKey: 'domainId', header: 'Scope' },
  { accessorKey: 'updatedAt', header: 'Updated' },
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

const domainFqdn = (id: string | null): string => {
  if (id === null) return 'All domains';
  return domainsStore.items.find((domain) => domain.id === id)?.fqdn ?? id.slice(0, 8);
};

// --- Override slideover -----------------------------------------------------
const slideoverOpen = ref(false);
const editingOverride = ref<CookieOverride | null>(null);

const overrideSchema = z.object({
  name_pattern: z.string().min(1).max(191),
  category: z.enum(['necessary', 'preferences', 'statistics', 'marketing']),
  domain_id: z.string().uuid().nullable().optional(),
  purpose: z.string().max(2000).nullable().optional(),
  justification: z.string().max(2000).nullable().optional(),
});

interface OverrideFormState {
  name_pattern: string;
  category: CookieCategory;
  domain_id: string | null;
  purpose: string;
  justification: string;
}

const overrideForm = reactive<OverrideFormState>({
  name_pattern: '',
  category: 'statistics',
  domain_id: null,
  purpose: '',
  justification: '',
});

const openCreate = (): void => {
  editingOverride.value = null;
  overrideForm.name_pattern = '';
  overrideForm.category = 'statistics';
  overrideForm.domain_id = null;
  overrideForm.purpose = '';
  overrideForm.justification = '';
  slideoverOpen.value = true;
};

const openEdit = (row: CookieOverride): void => {
  editingOverride.value = row;
  overrideForm.name_pattern = row.namePattern;
  overrideForm.category = row.category;
  overrideForm.domain_id = row.domainId;
  overrideForm.purpose = row.purpose ?? '';
  overrideForm.justification = row.justification ?? '';
  slideoverOpen.value = true;
};

const saving = ref(false);

const submitOverride = async (): Promise<void> => {
  saving.value = true;
  try {
    const payload: CookieOverrideInput = {
      name_pattern: overrideForm.name_pattern,
      category: overrideForm.category,
      domain_id: overrideForm.domain_id || null,
      purpose: overrideForm.purpose || null,
      justification: overrideForm.justification || null,
    };

    if (editingOverride.value) {
      await cookiesStore.updateOverride(editingOverride.value.id, payload);
      toast.add({ title: 'Override updated', color: 'success' });
    } else {
      await cookiesStore.createOverride(payload);
      toast.add({ title: 'Override created', color: 'success' });
    }
    slideoverOpen.value = false;
  } catch (caught) {
    toast.add({
      title: 'Save failed',
      description: caught instanceof Error ? caught.message : 'Please try again',
      color: 'error',
    });
  } finally {
    saving.value = false;
  }
};

const removeOverride = async (row: CookieOverride): Promise<void> => {
  if (!window.confirm(`Delete override for ${row.namePattern}?`)) return;
  await cookiesStore.deleteOverride(row.id);
  toast.add({ title: 'Override deleted', color: 'neutral' });
};

const categoryOptions: { value: CookieCategory; label: string }[] = [
  { value: 'necessary', label: 'Necessary' },
  { value: 'preferences', label: 'Preferences' },
  { value: 'statistics', label: 'Statistics' },
  { value: 'marketing', label: 'Marketing' },
];
</script>

<template>
  <UContainer class="py-6">
    <UPageHeader
      title="Cookie database"
      :description="`Canonical catalogue (${cookies.length}), per-org overrides (${overrides.length})${auth.user?.isSuperuser ? `, plus pending imports (${imports.length})` : ''}.`"
    />

    <div class="mt-4 flex flex-wrap gap-2">
      <UButton :variant="tab === 'canonical' ? 'solid' : 'soft'" @click="tab = 'canonical'">
        Canonical ({{ cookies.length }})
      </UButton>
      <UButton :variant="tab === 'overrides' ? 'solid' : 'soft'" @click="tab = 'overrides'">
        Overrides ({{ overrides.length }})
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

    <div v-else-if="tab === 'overrides'" class="mt-6">
      <div class="flex items-center justify-between mb-4">
        <p class="text-sm text-gray-500">
          Override the canonical categorisation for your organisation. Domain-scoped overrides win
          over org-wide ones; both win over canonical.
        </p>
        <UButton icon="i-lucide-plus" @click="openCreate">Add override</UButton>
      </div>

      <UTable :data="overrides" :columns="overrideColumns" :loading="loading">
        <template #category-cell="{ row }">
          <UBadge :color="categoryColor[row.original.category] ?? 'neutral'" variant="soft">
            {{ row.original.category }}
          </UBadge>
        </template>
        <template #domainId-cell="{ row }">
          <UBadge
            v-if="row.original.domainId === null"
            color="neutral"
            variant="subtle"
            icon="i-lucide-globe"
          >
            Org-wide
          </UBadge>
          <span v-else class="text-sm">{{ domainFqdn(row.original.domainId) }}</span>
        </template>
        <template #updatedAt-cell="{ row }">
          <span class="text-sm text-gray-500">
            {{ new Date(row.original.updatedAt).toLocaleDateString() }}
          </span>
        </template>
        <template #actions-cell="{ row }">
          <div class="flex gap-2">
            <UButton
              size="xs"
              variant="soft"
              icon="i-lucide-pencil"
              @click="openEdit(row.original)"
            />
            <UButton
              size="xs"
              variant="soft"
              color="error"
              icon="i-lucide-trash-2"
              @click="removeOverride(row.original)"
            />
          </div>
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
            <UButton size="xs" color="neutral" variant="soft" @click="reject(row.original.id)">
              Reject
            </UButton>
          </div>
        </template>
      </UTable>
    </div>

    <USlideover v-model:open="slideoverOpen" side="right">
      <template #content>
        <UCard>
          <template #header>
            <h3 class="font-semibold">
              {{ editingOverride ? 'Edit override' : 'New cookie override' }}
            </h3>
          </template>

          <UForm
            :schema="overrideSchema"
            :state="overrideForm"
            class="space-y-4"
            @submit="submitOverride"
          >
            <UFormField
              label="Name pattern"
              name="name_pattern"
              help="Use * as a wildcard — e.g. _ga_* matches every GA4 property cookie."
            >
              <UInput v-model="overrideForm.name_pattern" class="w-full" />
            </UFormField>

            <UFormField label="Category" name="category">
              <USelect
                v-model="overrideForm.category"
                :items="categoryOptions"
                value-key="value"
                class="w-full"
              />
            </UFormField>

            <UFormField
              label="Scope"
              name="domain_id"
              help="Leave blank to override across every domain in this organisation."
            >
              <USelectMenu
                v-model="overrideForm.domain_id"
                :items="[
                  { label: 'All domains (org-wide)', value: null },
                  ...domainsStore.items.map((domain) => ({
                    label: domain.fqdn,
                    value: domain.id,
                  })),
                ]"
                value-key="value"
                class="w-full"
              />
            </UFormField>

            <UFormField label="Purpose (optional)" name="purpose">
              <UTextarea
                v-model="overrideForm.purpose"
                :rows="2"
                class="w-full"
                placeholder="How this cookie is actually used on our property."
              />
            </UFormField>

            <UFormField
              label="Justification (optional)"
              name="justification"
              help="Internal audit trail — why we're overriding the canonical categorisation."
            >
              <UTextarea
                v-model="overrideForm.justification"
                :rows="2"
                class="w-full"
                placeholder="Approved by legal review on 2026-05-23, etc."
              />
            </UFormField>

            <div class="flex justify-end gap-2 pt-2">
              <UButton color="neutral" variant="soft" @click="slideoverOpen = false"
                >Cancel</UButton
              >
              <UButton type="submit" :loading="saving" icon="i-lucide-save">
                {{ editingOverride ? 'Save changes' : 'Create override' }}
              </UButton>
            </div>
          </UForm>
        </UCard>
      </template>
    </USlideover>
  </UContainer>
</template>
