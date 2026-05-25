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
const { cookies, imports, overrides, reviewQueue, loading } = storeToRefs(cookiesStore);
const auth = useAuthStore();
const toast = useToast();
const route = useRoute();

type Tab = 'canonical' | 'imports' | 'overrides' | 'review';
const initialTab = (route.query.tab as Tab | undefined) ?? 'canonical';
const tab = ref<Tab>(
  (['canonical', 'imports', 'overrides', 'review'] as const).includes(initialTab as Tab)
    ? initialTab
    : 'canonical',
);
const search = ref('');

onMounted(async () => {
  await Promise.all([
    cookiesStore.listCookies(),
    cookiesStore.listOverrides(),
    cookiesStore.listReviewQueue(),
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

const promotingAll = ref(false);
const onPromoteAll = async (): Promise<void> => {
  if (imports.value.length === 0) return;
  if (
    !window.confirm(
      `Promote all ${imports.value.length} pending row(s) into the canonical DB? Each one is added as if you clicked Promote individually — duplicates against existing canonical entries fail silently.`,
    )
  ) {
    return;
  }
  promotingAll.value = true;
  try {
    const result = await cookiesStore.promoteAll();
    toast.add({
      title: `Promoted ${result.promoted} row(s)`,
      description:
        result.failed > 0
          ? `${result.failed} failed${result.firstError ? ` — first error: ${result.firstError}` : ''}.`
          : 'All pending imports moved to the canonical DB.',
      icon: result.failed > 0 ? 'i-lucide-triangle-alert' : 'i-lucide-check',
      color: result.failed > 0 ? 'warning' : 'success',
    });
  } catch (caught) {
    toast.add({
      title: 'Could not promote all',
      description: caught instanceof Error ? caught.message : 'Please try again',
      icon: 'i-lucide-triangle-alert',
      color: 'error',
    });
  } finally {
    promotingAll.value = false;
  }
};

interface ImportFormState {
  file: File | null;
  sourceVersion: string;
}

const importForm = reactive<ImportFormState>({ file: null, sourceVersion: '' });
const uploading = ref(false);
const importSlideoverOpen = ref(false);
const importError = ref<string | null>(null);
const lastResult = ref<
  | { created: number; existing: number; skipped: number; sourceVersion: string }
  | null
>(null);

// Reset transient feedback whenever the slideover opens / closes so the
// next attempt starts on a clean slate.
watch(importSlideoverOpen, (isOpen: boolean): void => {
  if (!isOpen) return;
  importError.value = null;
  lastResult.value = null;
});

const onFileChange = (event: Event): void => {
  const target = event.target as HTMLInputElement;
  importForm.file = target.files?.[0] ?? null;
  importError.value = null;
};

/**
 * Pull the operator-facing message out of an Ofetch error. The Laravel
 * exception handler returns `{ message }` for our 422 cases; validation
 * errors come through under `errors[field][0]`. Bare 500s without our
 * own message fall back to a generic note that hides Laravel's
 * APP_DEBUG stack trace + file paths.
 */
const extractError = (caught: unknown): string => {
  if (caught instanceof Error) {
    const data = (caught as Error & {
      status?: number;
      data?: { message?: string; errors?: Record<string, string[]> };
    }).data;
    if (data?.message) return data.message;
    if (data?.errors) {
      const firstFieldErrors = Object.values(data.errors)[0];
      if (firstFieldErrors?.[0]) return firstFieldErrors[0];
    }
    const status = (caught as Error & { status?: number }).status;
    if (status === undefined || status >= 500) {
      return "Something went wrong on the server while parsing the file. Try again, and if it keeps failing share the file with the platform team.";
    }
  }
  return 'The upload failed. Please try again.';
};

const onUpload = async (): Promise<void> => {
  if (!importForm.file) return;
  uploading.value = true;
  importError.value = null;
  try {
    lastResult.value = await cookiesStore.uploadImport(
      importForm.file,
      importForm.sourceVersion,
    );
    toast.add({
      title: 'Cookie list imported',
      description: `${lastResult.value.created} new row(s) staged for review.`,
      icon: 'i-lucide-check',
      color: 'success',
    });
    importForm.file = null;
    importForm.sourceVersion = '';
    // Switch the tab so the staged rows are visible behind the slideover
    // when it closes, then dismiss after a beat so the success alert is
    // readable.
    if (auth.user?.isSuperuser) tab.value = 'imports';
    setTimeout(() => {
      importSlideoverOpen.value = false;
    }, 1500);
  } catch (caught) {
    importError.value = extractError(caught);
  } finally {
    uploading.value = false;
  }
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

const openCreate = (prefillName?: string): void => {
  editingOverride.value = null;
  overrideForm.name_pattern = prefillName ?? '';
  overrideForm.category = 'statistics';
  overrideForm.domain_id = null;
  overrideForm.purpose = '';
  overrideForm.justification = '';
  slideoverOpen.value = true;
};

const triageFromReview = (item: { name: string }): void => {
  openCreate(item.name);
};

const formatRelative = (iso: string | null): string => {
  if (!iso) return '—';
  return new Date(iso).toLocaleString();
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
    // Either path may have removed a name from the review queue, refresh it.
    await cookiesStore.listReviewQueue();
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
  <PageHeader
    title="Cookie database"
    :description="`Canonical catalogue (${cookies.length}), per-org overrides (${overrides.length})${auth.user?.isSuperuser ? `, plus pending imports (${imports.length})` : ''}.`"
  >
    <template #right>
      <UButton
        v-if="auth.user?.isSuperuser"
        icon="i-lucide-upload"
        label="Import cookies"
        @click="importSlideoverOpen = true"
      />
    </template>
  </PageHeader>

  <div class="px-4 sm:px-6 lg:px-8 pt-4 pb-6">

    <div class="mt-4 flex flex-wrap gap-2">
      <UButton :variant="tab === 'canonical' ? 'solid' : 'soft'" @click="tab = 'canonical'">
        Canonical ({{ cookies.length }})
      </UButton>
      <UButton :variant="tab === 'overrides' ? 'solid' : 'soft'" @click="tab = 'overrides'">
        Overrides ({{ overrides.length }})
      </UButton>
      <UButton
        :variant="tab === 'review' ? 'solid' : 'soft'"
        :color="reviewQueue.length > 0 ? 'warning' : 'neutral'"
        @click="tab = 'review'"
      >
        Review queue ({{ reviewQueue.length }})
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
        <UButton icon="i-lucide-plus" @click="openCreate()">Add override</UButton>
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

    <div v-else-if="tab === 'review'" class="mt-6">
      <p class="text-sm text-gray-500 mb-4">
        Cookies the scanner observed that aren't in the canonical DB or any of your overrides.
        Triage by creating an override — the queue shrinks as soon as the new override would match
        this name.
      </p>

      <UCard v-if="reviewQueue.length === 0">
        <div class="flex flex-col items-center gap-2 py-12 text-center">
          <UIcon name="i-lucide-shield-check" class="text-4xl text-success-500" />
          <h2 class="text-lg font-semibold">No unknown cookies</h2>
          <p class="text-sm text-gray-500 max-w-md">
            Every cookie the scanner has seen for your organisation is matched by the canonical DB
            or an existing override.
          </p>
        </div>
      </UCard>

      <UTable
        v-else
        :data="reviewQueue"
        :columns="[
          { accessorKey: 'name', header: 'Cookie name' },
          { accessorKey: 'occurrences', header: 'Observed' },
          { accessorKey: 'firstSeenUrl', header: 'First seen on' },
          { accessorKey: 'lastSeenAt', header: 'Last seen' },
          { id: 'actions', header: '' },
        ]"
        :loading="loading"
      >
        <template #name-cell="{ row }">
          <code class="text-sm">{{ row.original.name }}</code>
        </template>
        <template #occurrences-cell="{ row }">
          {{ row.original.occurrences }}× across {{ row.original.scanCount }} scan{{
            row.original.scanCount === 1 ? '' : 's'
          }}
        </template>
        <template #firstSeenUrl-cell="{ row }">
          <span class="text-xs text-gray-500 break-all">
            {{ row.original.firstSeenUrl ?? '—' }}
          </span>
        </template>
        <template #lastSeenAt-cell="{ row }">
          <span class="text-xs">{{ formatRelative(row.original.lastSeenAt) }}</span>
        </template>
        <template #actions-cell="{ row }">
          <UButton
            size="xs"
            icon="i-lucide-plus"
            label="Create override"
            @click="triageFromReview(row.original)"
          />
        </template>
      </UTable>
    </div>

    <div v-else class="mt-6">
      <div class="flex items-start justify-between gap-3 mb-4">
        <p class="text-sm text-gray-500">
          Rows staged from CSV uploads via the
          <strong>Import cookies</strong> button above. Promote each row into
          the canonical DB or reject it.
        </p>
        <UButton
          v-if="imports.length > 0"
          icon="i-lucide-check-check"
          color="success"
          variant="soft"
          size="sm"
          :loading="promotingAll"
          :label="`Promote all (${imports.length})`"
          @click="onPromoteAll"
        />
      </div>
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

    <USlideover v-model:open="importSlideoverOpen" title="Import cookies">
      <template #body>
        <UForm :state="importForm" class="space-y-4" @submit="onUpload">
          <p class="text-sm text-gray-500">
            Upload an
            <a
              href="https://github.com/jkwakman/Open-Cookie-Database/blob/master/open-cookie-database.csv"
              target="_blank"
              rel="noopener"
              class="text-primary-500 hover:underline"
            >Open-Cookie-Database</a>
            CSV (or any file matching the same column layout) to stage rows for review. Rows are
            parsed into the queue on the <strong>Pending imports</strong> tab — promote each one
            into the canonical DB or reject. Re-uploading the same dataset is safe; duplicates
            count as <em>existing</em> rather than re-staging.
          </p>

          <UFormField
            label="CSV file"
            name="file"
            required
            help="Required columns: Cookie / Data Key name, Category. Optional: Platform (or Vendor), Domain, Description, Retention period, Wildcard match. Extra columns are ignored."
          >
            <UInput
              type="file"
              accept=".csv,text/csv"
              :disabled="uploading"
              @change="onFileChange"
            />
            <p v-if="importForm.file" class="mt-1 text-xs text-gray-500">
              <UIcon name="i-lucide-file-text" class="inline-block align-middle" />
              {{ importForm.file.name }} ({{ Math.round(importForm.file.size / 1024) }} KB)
            </p>
          </UFormField>
          <UFormField
            label="Source version (optional)"
            name="source_version"
            help="A label to distinguish this upload in the queue (e.g. '2026-05-25', 'ocd-v1.4'). Leave blank to auto-stamp."
          >
            <UInput
              v-model="importForm.sourceVersion"
              placeholder="e.g. 2026-05-25"
              :disabled="uploading"
              class="w-full"
            />
          </UFormField>
          <UAlert
            v-if="importError"
            color="error"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            title="Couldn't import this CSV"
            :description="importError"
          />
          <UAlert
            v-if="lastResult"
            color="success"
            variant="subtle"
            icon="i-lucide-check"
            :title="`Imported ${lastResult.sourceVersion}`"
            :description="`Created ${lastResult.created}, already existed ${lastResult.existing}, skipped ${lastResult.skipped}.`"
          />
          <div class="flex items-center justify-between gap-3 pt-2">
            <UButton
              variant="ghost"
              color="neutral"
              size="sm"
              icon="i-lucide-download"
              label="Download example.csv"
              to="/examples/cookie-import-example.csv"
              target="_blank"
              external
            />
            <div class="flex gap-2">
              <UButton
                color="neutral"
                variant="ghost"
                label="Close"
                @click="importSlideoverOpen = false"
              />
              <UButton
                type="submit"
                icon="i-lucide-upload"
                label="Upload + stage"
                :loading="uploading"
                :disabled="!importForm.file"
              />
            </div>
          </div>
        </UForm>
      </template>
    </USlideover>

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
  </div>
</template>
