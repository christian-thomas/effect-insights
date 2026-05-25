<script setup lang="ts">
import type { BadgeProps, TableColumn } from '@nuxt/ui';
import type { ScanFinding } from '~/types/scans';

type BadgeColor = NonNullable<BadgeProps['color']>;

const route = useRoute();
const scansStore = useScansStore();
const { selected: scan, findings, diff, loading } = storeToRefs(scansStore);

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
  <PageHeader
    :title="scan ? `Scan ${scan.id.slice(0, 8)}…` : 'Scan'"
    :description="scan ? `Status: ${scan.status} · ${scan.urlsScanned} URLs scanned · ${findings.length} findings` : undefined"
  />

  <div class="px-4 sm:px-6 lg:px-8 pt-4 pb-6 space-y-4">
    <div v-if="scan">
      <UAlert
        v-if="scan.errorLog"
        color="error"
        variant="soft"
        :title="`Scan failed: ${String(scan.errorLog.message ?? 'unknown error')}`"
        class="mt-4"
      />

      <UCard v-if="diff" class="mt-6">
        <template #header>
          <div class="flex items-center justify-between">
            <h3 class="font-semibold">Compared to previous scan</h3>
            <NuxtLink
              :to="`/scans/${diff.previousScanId}`"
              class="text-xs text-gray-500 hover:underline"
            >
              {{ diff.previousScanId.slice(0, 8) }}…
            </NuxtLink>
          </div>
        </template>

        <div
          v-if="
            diff.new.length === 0 && diff.removed.length === 0 && diff.categoryChanged.length === 0
          "
        >
          <p class="text-sm text-gray-500">
            No changes since the previous scan — same cookies, same categories.
          </p>
        </div>

        <div v-else class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div>
            <div class="flex items-center gap-2 mb-2">
              <UBadge color="success" variant="soft">New ({{ diff.new.length }})</UBadge>
            </div>
            <ul v-if="diff.new.length > 0" class="space-y-1 text-sm">
              <li v-for="entry in diff.new" :key="entry.name" class="font-mono text-xs">
                {{ entry.name }}
                <span v-if="entry.category" class="text-gray-500"> · {{ entry.category }}</span>
              </li>
            </ul>
            <p v-else class="text-xs text-gray-500">No new cookies.</p>
          </div>

          <div>
            <div class="flex items-center gap-2 mb-2">
              <UBadge color="error" variant="soft">Removed ({{ diff.removed.length }})</UBadge>
            </div>
            <ul v-if="diff.removed.length > 0" class="space-y-1 text-sm">
              <li v-for="entry in diff.removed" :key="entry.name" class="font-mono text-xs">
                {{ entry.name }}
                <span v-if="entry.category" class="text-gray-500"> · {{ entry.category }}</span>
              </li>
            </ul>
            <p v-else class="text-xs text-gray-500">Nothing dropped off.</p>
          </div>

          <div>
            <div class="flex items-center gap-2 mb-2">
              <UBadge color="warning" variant="soft">
                Category changes ({{ diff.categoryChanged.length }})
              </UBadge>
            </div>
            <ul v-if="diff.categoryChanged.length > 0" class="space-y-1 text-sm">
              <li v-for="entry in diff.categoryChanged" :key="entry.name" class="text-xs">
                <span class="font-mono">{{ entry.name }}</span>
                <span class="text-gray-500">
                  : {{ entry.previousCategory ?? 'unknown' }} →
                  {{ entry.currentCategory ?? 'unknown' }}
                </span>
              </li>
            </ul>
            <p v-else class="text-xs text-gray-500">No re-categorisations.</p>
          </div>
        </div>
      </UCard>

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
  </div>
</template>
