<script setup lang="ts">
import { z } from 'zod';
import type { FormSubmitEvent, TableColumn } from '@nuxt/ui';
import type { Organisation, OrganisationInput } from '~/types/organisation';

const auth = useAuthStore();
const toast = useToast();

if (!auth.user?.isSuperuser) {
  throw createError({ statusCode: 403, statusMessage: 'Superuser role required' });
}

const organisationsStore = useOrganisationsStore();
const { items: organisations, loading, error } = storeToRefs(organisationsStore);
await organisationsStore.list();

const schema = z.object({
  name: z.string().min(1, 'Name is required').max(120),
  slug: z
    .string()
    .min(1, 'Slug is required')
    .max(64)
    .regex(/^[a-z0-9]+(?:-[a-z0-9]+)*$/, 'lowercase letters, digits, single hyphens'),
  consent_retention_days: z.number().int().min(1).max(3650).nullable(),
  notification_email: z.string().max(320).nullable(),
});

const slideoverOpen = ref(false);
const editing = ref<Organisation | null>(null);
const state = reactive<Partial<OrganisationInput>>({});
const submitting = ref(false);
// Mirror the nullable retention field with a boolean for the toggle —
// the API uses NULL = indefinite but a toggle is friendlier in the UI.
const retentionIndefinite = ref(true);
const retentionDays = ref<number>(365);

const openCreate = (): void => {
  editing.value = null;
  state.name = '';
  state.slug = '';
  state.consent_retention_days = null;
  state.notification_email = null;
  retentionIndefinite.value = true;
  retentionDays.value = 365;
  slideoverOpen.value = true;
};

const openEdit = (organisation: Organisation): void => {
  editing.value = organisation;
  state.name = organisation.name;
  state.slug = organisation.slug;
  state.consent_retention_days = organisation.consentRetentionDays;
  state.notification_email = organisation.notificationEmail;
  retentionIndefinite.value = organisation.consentRetentionDays === null;
  retentionDays.value = organisation.consentRetentionDays ?? 365;
  slideoverOpen.value = true;
};

// Keep state.consent_retention_days in sync with whichever input is
// active: when the toggle is on we send null (indefinite); otherwise
// we send the number from the days input.
watch([retentionIndefinite, retentionDays], (): void => {
  state.consent_retention_days = retentionIndefinite.value ? null : retentionDays.value;
});

const onSubmit = async (event: FormSubmitEvent<OrganisationInput>): Promise<void> => {
  submitting.value = true;
  try {
    if (editing.value) {
      await organisationsStore.update(editing.value.id, event.data);
      toast.add({ title: 'Organisation updated', color: 'success', icon: 'i-lucide-check' });
    } else {
      await organisationsStore.create(event.data);
      toast.add({ title: 'Organisation created', color: 'success', icon: 'i-lucide-check' });
    }
    slideoverOpen.value = false;
  } catch (caught) {
    toast.add({
      title: 'Save failed',
      description: caught instanceof Error ? caught.message : 'Please try again',
      color: 'error',
      icon: 'i-lucide-triangle-alert',
    });
  } finally {
    submitting.value = false;
  }
};

const confirmDelete = async (organisation: Organisation): Promise<void> => {
  if (
    !window.confirm(`Delete "${organisation.name}"? Its domains and consent log will be removed.`)
  ) {
    return;
  }
  try {
    await organisationsStore.remove(organisation.id);
    toast.add({ title: 'Organisation deleted', color: 'success', icon: 'i-lucide-check' });
  } catch (caught) {
    toast.add({
      title: 'Could not delete',
      description: caught instanceof Error ? caught.message : 'Please try again',
      color: 'error',
      icon: 'i-lucide-triangle-alert',
    });
  }
};

const columns: TableColumn<Organisation>[] = [
  { accessorKey: 'name', header: 'Name' },
  { accessorKey: 'slug', header: 'Slug' },
  { accessorKey: 'consentRetentionDays', header: 'Consent retention' },
  { id: 'actions', header: '' },
];
</script>

<template>
  <UDashboardNavbar title="Organisations">
    <template #right>
      <UButton icon="i-lucide-plus" label="New organisation" @click="openCreate" />
    </template>
  </UDashboardNavbar>

  <div class="p-4 sm:p-6 lg:p-8">
    <UAlert
      v-if="error"
      class="mb-4"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :title="error.message"
    />

    <UCard v-if="!loading && organisations.length === 0">
      <div class="flex flex-col items-center gap-2 py-12 text-center">
        <UIcon name="i-lucide-building" class="text-4xl text-gray-400" />
        <h2 class="text-lg font-semibold">No organisations yet</h2>
        <p class="text-sm text-gray-500 max-w-md">
          Provision white-label tenants for clients or resellers here. You'll be able to switch into
          each tenant from the sidebar to manage their domains.
        </p>
        <UButton
          class="mt-2"
          icon="i-lucide-plus"
          label="Create the first organisation"
          @click="openCreate"
        />
      </div>
    </UCard>

    <UTable v-else :data="organisations" :columns="columns" :loading="loading" class="w-full">
      <template #slug-cell="{ row }">
        <code class="text-sm">{{ row.original.slug }}</code>
      </template>
      <template #consentRetentionDays-cell="{ row }">
        <span v-if="row.original.consentRetentionDays === null" class="text-sm text-gray-500">
          Indefinite
        </span>
        <span v-else class="text-sm">
          {{ row.original.consentRetentionDays }} days
        </span>
      </template>
      <template #actions-cell="{ row }">
        <div class="flex justify-end gap-1">
          <UButton
            color="neutral"
            variant="ghost"
            icon="i-lucide-pencil"
            size="xs"
            @click="openEdit(row.original)"
          />
          <UButton
            color="error"
            variant="ghost"
            icon="i-lucide-trash-2"
            size="xs"
            @click="confirmDelete(row.original)"
          />
        </div>
      </template>
    </UTable>
  </div>

  <USlideover
    v-model:open="slideoverOpen"
    :title="editing ? `Edit ${editing.name}` : 'New organisation'"
  >
    <template #body>
      <UForm :schema="schema" :state="state" class="space-y-4" @submit="onSubmit">
        <UFormField label="Name" name="name">
          <UInput v-model="state.name" placeholder="Acme Corp" class="w-full" />
        </UFormField>
        <UFormField label="Slug" name="slug" help="Used in URLs and CDN paths. Lowercase, hyphens.">
          <UInput v-model="state.slug" placeholder="acme-corp" class="w-full" />
        </UFormField>

        <div class="pt-4 mt-4 border-t border-gray-200 dark:border-gray-800 space-y-3">
          <div>
            <p class="text-sm font-medium">Consent event retention</p>
            <p class="text-xs text-gray-500">
              How long to keep this tenant's consent log in the MySQL archive. The nightly
              <code>consent-events:purge</code> command hard-deletes anything older.
            </p>
          </div>
          <div class="flex items-start justify-between gap-4">
            <div class="flex-1">
              <p class="text-sm">Retain indefinitely</p>
              <p class="text-xs text-gray-500">
                Default. Consent events stay in the archive until you delete the parent domain.
              </p>
            </div>
            <USwitch v-model="retentionIndefinite" />
          </div>
          <UFormField
            v-if="!retentionIndefinite"
            label="Retain for"
            name="consent_retention_days"
            help="Days. Min 1, max 3650 (10 years)."
          >
            <div class="flex items-center gap-2">
              <UInput
                v-model.number="retentionDays"
                type="number"
                :min="1"
                :max="3650"
                class="w-32"
              />
              <span class="text-sm text-gray-500">days</span>
            </div>
          </UFormField>
          <UAlert
            v-if="!retentionIndefinite"
            color="warning"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            title="Hard delete"
            description="The purge bypasses receipts and the archive — deleted events cannot be re-downloaded."
          />
        </div>

        <div class="pt-4 mt-4 border-t border-gray-200 dark:border-gray-800 space-y-3">
          <div>
            <p class="text-sm font-medium">Email alert recipients</p>
            <p class="text-xs text-gray-500">
              Comma-separated. Fires in addition to Slack for: unknown cookies, cookie scan
              failures, accessibility violations (at/above the threshold), and accessibility scan
              failures. Leave blank for Slack-only.
            </p>
          </div>
          <UFormField label="Email(s)" name="notification_email">
            <UInput
              :model-value="state.notification_email ?? ''"
              type="text"
              placeholder="alerts@client.com, ops@client.com"
              class="w-full"
              @update:model-value="(value: string) => state.notification_email = value === '' ? null : String(value)"
            />
          </UFormField>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <UButton color="neutral" variant="ghost" label="Cancel" @click="slideoverOpen = false" />
          <UButton
            type="submit"
            :loading="submitting"
            :label="editing ? 'Save changes' : 'Create'"
          />
        </div>
      </UForm>
    </template>
  </USlideover>
</template>
