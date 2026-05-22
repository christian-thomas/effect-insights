<script setup lang="ts">
import { z } from 'zod';
import type { FormSubmitEvent, TableColumn } from '@nuxt/ui';
import type { Organisation } from '~/stores/auth';
import type { OrganisationInput } from '~/stores/organisations';

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
});

const slideoverOpen = ref(false);
const editing = ref<Organisation | null>(null);
const state = reactive<Partial<OrganisationInput>>({});
const submitting = ref(false);

const openCreate = (): void => {
  editing.value = null;
  state.name = '';
  state.slug = '';
  slideoverOpen.value = true;
};

const openEdit = (organisation: Organisation): void => {
  editing.value = organisation;
  state.name = organisation.name;
  state.slug = organisation.slug;
  slideoverOpen.value = true;
};

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
  if (!window.confirm(`Delete "${organisation.name}"? Its domains and consent log will be removed.`)) {
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
          Provision white-label tenants for clients or resellers here. You'll be able to
          switch into each tenant from the sidebar to manage their domains.
        </p>
        <UButton class="mt-2" icon="i-lucide-plus" label="Create the first organisation" @click="openCreate" />
      </div>
    </UCard>

    <UTable
      v-else
      :data="organisations"
      :columns="columns"
      :loading="loading"
      class="w-full"
    >
      <template #slug-cell="{ row }">
        <code class="text-sm">{{ row.original.slug }}</code>
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

  <USlideover v-model:open="slideoverOpen" :title="editing ? `Edit ${editing.name}` : 'New organisation'">
    <template #body>
      <UForm :schema="schema" :state="state" class="space-y-4" @submit="onSubmit">
        <UFormField label="Name" name="name">
          <UInput v-model="state.name" placeholder="Acme Corp" class="w-full" />
        </UFormField>
        <UFormField label="Slug" name="slug" help="Used in URLs and CDN paths. Lowercase, hyphens.">
          <UInput v-model="state.slug" placeholder="acme-corp" class="w-full" />
        </UFormField>
        <div class="flex justify-end gap-2 pt-2">
          <UButton color="neutral" variant="ghost" label="Cancel" @click="slideoverOpen = false" />
          <UButton type="submit" :loading="submitting" :label="editing ? 'Save changes' : 'Create'" />
        </div>
      </UForm>
    </template>
  </USlideover>
</template>
