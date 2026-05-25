<script setup lang="ts">
import type { BadgeProps, TableColumn } from '@nuxt/ui';
import type { AccessibilityScan, A11yImpact } from '~/types/accessibility';

type BadgeColor = NonNullable<BadgeProps['color']>;

const scansStore = useAccessibilityScansStore();
const domainsStore = useDomainsStore();
const { scans, loading, triggering } = storeToRefs(scansStore);
const toast = useToast();

await Promise.all([scansStore.list(), domainsStore.list()]);

const selectedDomain = ref<string>('');

const columns: TableColumn<AccessibilityScan>[] = [
  { accessorKey: 'createdAt', header: 'Triggered' },
  { accessorKey: 'domainId', header: 'Domain' },
  { accessorKey: 'status', header: 'Status' },
  { accessorKey: 'trigger', header: 'Trigger' },
  { accessorKey: 'violationsTotal', header: 'Violations' },
  { id: 'actions', header: '' },
];

const statusColor: Record<string, BadgeColor> = {
  queued: 'neutral',
  running: 'info',
  completed: 'success',
  failed: 'error',
};

const impactColor: Record<A11yImpact, BadgeColor> = {
  critical: 'error',
  serious: 'warning',
  moderate: 'info',
  minor: 'neutral',
};

const triggerScan = async (): Promise<void> => {
  if (!selectedDomain.value) return;
  const scan = await scansStore.trigger(selectedDomain.value);
  await scansStore.list();
  toast.add({
    title: `Scan ${scan.status}`,
    description: `Queued — refresh shortly to see results.`,
    color: 'success',
    icon: 'i-lucide-check',
  });
};

const domainFqdn = (id: string): string =>
  domainsStore.items.find((domain) => domain.id === id)?.fqdn ?? '—';
</script>

<template>
  <UContainer class="py-6">
    <UPageHeader
      title="Accessibility scans"
      description="Scheduled and manual axe-core scans across the active organisation's domains."
    >
      <template #links>
        <UButton
          to="/accessibility-rule-overrides"
          color="neutral"
          variant="soft"
          size="sm"
          icon="i-lucide-volume-x"
        >
          Rule overrides
        </UButton>
      </template>
    </UPageHeader>

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
          icon="i-lucide-accessibility"
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
      <template #violationsTotal-cell="{ row }">
        <div class="flex items-center gap-2">
          <span class="font-medium tabular-nums">{{ row.original.violationsTotal }}</span>
          <div v-if="row.original.violationsByImpact" class="flex items-center gap-1">
            <UBadge
              v-for="impact in ['critical', 'serious', 'moderate', 'minor'] as A11yImpact[]"
              :key="impact"
              :color="impactColor[impact]"
              variant="subtle"
              size="sm"
              :title="`${impact}: ${row.original.violationsByImpact[impact]}`"
            >
              {{ row.original.violationsByImpact[impact] }}
            </UBadge>
          </div>
        </div>
      </template>
      <template #actions-cell="{ row }">
        <UButton size="xs" variant="soft" :to="`/accessibility-scans/${row.original.id}`">
          View
        </UButton>
      </template>
    </UTable>
  </UContainer>
</template>
