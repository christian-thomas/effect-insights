<script setup lang="ts">
import type { BadgeProps, TableColumn } from '@nuxt/ui';
import type { ScanFinding } from '~/types/scans';

type BadgeColor = NonNullable<BadgeProps['color']>;

const route = useRoute();
const scansStore = useScansStore();
const { selected: scan, findings, loading } = storeToRefs(scansStore);

const scanId = computed((): string => String(route.params.id));

await scansStore.show(scanId.value);

const findingsByCategory = computed((): Record<string, ScanFinding[]> => {
  const groups: Record<string, ScanFinding[]> = {};
  for (const finding of findings.value) {
    const key = finding.category ?? '__unknown__';
    if (!groups[key]) groups[key] = [];
    groups[key].push(finding);
  }
  return groups;
});

const columns: TableColumn<ScanFinding>[] = [
  { accessorKey: 'type', header: 'Type' },
  { accessorKey: 'name', header: 'Name' },
  { accessorKey: 'domainSetOn', header: 'Set on' },
  { accessorKey: 'party', header: 'Party' },
  { accessorKey: 'categorySource', header: 'Categorised by' },
];

const categoryColor: Record<string, BadgeColor> = {
  necessary: 'success',
  preferences: 'info',
  statistics: 'warning',
  marketing: 'error',
  __unknown__: 'neutral',
};

const categoryLabel = (key: string): string =>
  key === '__unknown__' ? 'Unknown — review needed' : key;
</script>

<template>
  <UContainer class="py-6">
    <div v-if="scan">
      <UPageHeader
        :title="`Scan ${scan.id.slice(0, 8)}…`"
        :description="`Status: ${scan.status} · ${scan.urlsScanned} URLs scanned · ${findings.length} findings`"
      />

      <UAlert
        v-if="scan.errorLog"
        color="error"
        variant="soft"
        :title="`Scan failed: ${String(scan.errorLog.message ?? 'unknown error')}`"
        class="mt-4"
      />

      <div v-for="(group, key) in findingsByCategory" :key="key" class="mt-6">
        <div class="flex items-center gap-2 mb-2">
          <UBadge :color="categoryColor[key] ?? 'neutral'" variant="soft">
            {{ categoryLabel(key) }}
          </UBadge>
          <span class="text-sm text-gray-500">{{ group.length }} findings</span>
        </div>
        <UTable :data="group" :columns="columns" :loading="loading" />
      </div>

      <UAlert
        v-if="findings.length === 0 && !loading"
        title="No findings yet"
        description="The scan completed without observing any cookies or scripts."
        color="neutral"
        variant="subtle"
        class="mt-4"
      />
    </div>
  </UContainer>
</template>
