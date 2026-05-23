<script setup lang="ts">
import type { BadgeProps, TableColumn } from '@nuxt/ui';
import type { Scan } from '~/types/scans';

type BadgeColor = NonNullable<BadgeProps['color']>;

const scansStore = useScansStore();
const domainsStore = useDomainsStore();
const { scans, loading, triggering } = storeToRefs(scansStore);
const toast = useToast();

await Promise.all([scansStore.list(), domainsStore.list()]);

const selectedDomain = ref<string>('');

const columns: TableColumn<Scan>[] = [
  { accessorKey: 'createdAt', header: 'Triggered' },
  { accessorKey: 'domainId', header: 'Domain' },
  { accessorKey: 'status', header: 'Status' },
  { accessorKey: 'trigger', header: 'Trigger' },
  { accessorKey: 'urlsScanned', header: 'URLs scanned' },
  { id: 'actions', header: '' },
];

const statusColor: Record<string, BadgeColor> = {
  queued: 'neutral',
  running: 'info',
  completed: 'success',
  failed: 'error',
};

const triggerScan = async (): Promise<void> => {
  if (!selectedDomain.value) return;
  const scan = await scansStore.trigger(selectedDomain.value);
  toast.add({
    title: `Scan ${scan.status}`,
    description: `${scan.urlsScanned} URLs scanned`,
    color: scan.status === 'completed' ? 'success' : 'warning',
  });
};

const domainFqdn = (id: string): string =>
  domainsStore.items.find((domain) => domain.id === id)?.fqdn ?? '—';
</script>

<template>
  <UContainer class="py-6">
    <UPageHeader
      title="Scans"
      description="Triggered scans across the active organisation's domains."
    />

    <UCard class="mt-4">
      <div class="flex items-center gap-3">
        <USelectMenu
          v-model="selectedDomain"
          :items="domainsStore.items.map((domain) => ({ label: domain.fqdn, value: domain.id }))"
          value-key="value"
          placeholder="Pick a domain to scan"
          class="w-80"
        />
        <UButton
          icon="i-lucide-radar"
          :loading="triggering"
          :disabled="!selectedDomain"
          @click="triggerScan"
        >
          Run scan now
        </UButton>
      </div>
    </UCard>

    <UTable :data="scans" :columns="columns" :loading="loading" class="mt-6">
      <template #createdAt-cell="{ row }">
        <span class="text-sm">{{ new Date(row.original.createdAt).toLocaleString() }}</span>
      </template>
      <template #domainId-cell="{ row }">
        <NuxtLink
          :to="`/domains/${row.original.domainId}`"
          class="text-primary-600 hover:underline"
        >
          {{ domainFqdn(row.original.domainId) }}
        </NuxtLink>
      </template>
      <template #status-cell="{ row }">
        <UBadge :color="statusColor[row.original.status] ?? 'neutral'" variant="soft">
          {{ row.original.status }}
        </UBadge>
      </template>
      <template #actions-cell="{ row }">
        <UButton size="xs" variant="soft" :to="`/scans/${row.original.id}`">View</UButton>
      </template>
    </UTable>
  </UContainer>
</template>
