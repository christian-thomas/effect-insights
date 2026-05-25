<script setup lang="ts">
import { domainInputSchema, type Domain, type DomainInput } from '@effectinsight/types/domain';
import type { FormSubmitEvent, TableColumn } from '@nuxt/ui';

const domainsStore = useDomainsStore();
const { items: domains, loading, error } = storeToRefs(domainsStore);
const toast = useToast();

await domainsStore.list();

const slideoverOpen = ref(false);
const editing = ref<Domain | null>(null);
const state = reactive<Partial<DomainInput>>({});
const submitting = ref(false);

const openCreate = (): void => {
  editing.value = null;
  state.fqdn = '';
  state.status = 'pending';
  slideoverOpen.value = true;
};

const openEdit = (domain: Domain): void => {
  editing.value = domain;
  state.fqdn = domain.fqdn;
  state.status = domain.status;
  slideoverOpen.value = true;
};

const onSubmit = async (event: FormSubmitEvent<DomainInput>): Promise<void> => {
  submitting.value = true;
  try {
    if (editing.value) {
      await domainsStore.update(editing.value.id, event.data);
      toast.add({ title: 'Domain updated', color: 'success', icon: 'i-lucide-check' });
    } else {
      await domainsStore.create(event.data);
      toast.add({ title: 'Domain added', color: 'success', icon: 'i-lucide-check' });
    }
    slideoverOpen.value = false;
  } catch (caught) {
    toast.add({
      title: editing.value ? 'Could not update domain' : 'Could not add domain',
      description: caught instanceof Error ? caught.message : 'Please try again',
      color: 'error',
      icon: 'i-lucide-triangle-alert',
    });
  } finally {
    submitting.value = false;
  }
};

const confirmDelete = async (domain: Domain): Promise<void> => {
  if (!window.confirm(`Remove ${domain.fqdn}? Consent records will be retained.`)) {
    return;
  }
  try {
    await domainsStore.remove(domain.id);
    toast.add({ title: `Removed ${domain.fqdn}`, color: 'success', icon: 'i-lucide-check' });
  } catch (caught) {
    toast.add({
      title: 'Could not remove domain',
      description: caught instanceof Error ? caught.message : 'Please try again',
      color: 'error',
      icon: 'i-lucide-triangle-alert',
    });
  }
};

const statusColor = (status: Domain['status']): 'neutral' | 'success' | 'warning' => {
  if (status === 'active') return 'success';
  if (status === 'paused') return 'warning';
  return 'neutral';
};

const columns: TableColumn<Domain>[] = [
  { accessorKey: 'fqdn', header: 'FQDN' },
  { accessorKey: 'apexDomain', header: 'Apex' },
  { accessorKey: 'status', header: 'Status', id: 'status' },
  { id: 'actions', header: '' },
];
</script>

<template>
  <UDashboardNavbar title="Domains">
    <template #right>
      <UButton icon="i-lucide-plus" label="Add domain" @click="openCreate" />
      <div class="mx-1 h-6 w-px bg-accented" aria-hidden="true" />
      <OrgSwitcher />
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

    <UCard v-if="!loading && domains.length === 0">
      <div class="flex flex-col items-center gap-2 py-12 text-center">
        <UIcon name="i-lucide-globe" class="text-4xl text-gray-400" />
        <h2 class="text-lg font-semibold">No domains yet</h2>
        <p class="text-sm text-gray-500 max-w-md">
          Add the first client site to this organisation. You'll get an embed snippet to drop into
          the site so the CMP can render and log consent.
        </p>
        <UButton
          class="mt-2"
          icon="i-lucide-plus"
          label="Add the first domain"
          @click="openCreate"
        />
      </div>
    </UCard>

    <UTable v-else :data="domains" :columns="columns" :loading="loading" class="w-full">
      <template #fqdn-cell="{ row }">
        <NuxtLink :to="`/domains/${row.original.id}`" class="font-medium hover:underline">
          {{ row.original.fqdn }}
        </NuxtLink>
      </template>
      <template #status-cell="{ row }">
        <UBadge :color="statusColor(row.original.status)" variant="subtle">
          {{ row.original.status }}
        </UBadge>
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

  <USlideover v-model:open="slideoverOpen" :title="editing ? `Edit ${editing.fqdn}` : 'Add domain'">
    <template #body>
      <UForm :schema="domainInputSchema" :state="state" class="space-y-4" @submit="onSubmit">
        <UFormField
          label="FQDN"
          name="fqdn"
          help="The fully-qualified domain name (e.g. www.example.com)."
        >
          <UInput
            v-model="state.fqdn"
            placeholder="example.com"
            class="w-full"
            autocomplete="off"
          />
        </UFormField>

        <UFormField label="Status" name="status">
          <USelect
            v-model="state.status"
            :items="[
              { label: 'Pending', value: 'pending' },
              { label: 'Active', value: 'active' },
              { label: 'Paused', value: 'paused' },
            ]"
            class="w-full"
          />
        </UFormField>

        <div class="flex justify-end gap-2 pt-2">
          <UButton color="neutral" variant="ghost" label="Cancel" @click="slideoverOpen = false" />
          <UButton
            type="submit"
            :loading="submitting"
            :label="editing ? 'Save changes' : 'Add domain'"
          />
        </div>
      </UForm>
    </template>
  </USlideover>
</template>
