<script setup lang="ts">
import type { BadgeProps, TableColumn } from '@nuxt/ui';
import type { Scan } from '~/types/scans';

type BadgeColor = NonNullable<BadgeProps['color']>;

const route = useRoute();
const scansStore = useScansStore();
const domainsStore = useDomainsStore();
const { scans, loading, triggering } = storeToRefs(scansStore);
const toast = useToast();

const domainId = computed((): string => String(route.params.id));

await Promise.all([scansStore.list(domainId.value), domainsStore.list()]);

const domain = computed(() => domainsStore.findById(domainId.value));

const columns: TableColumn<Scan>[] = [
  { accessorKey: 'createdAt', header: 'Triggered' },
  { accessorKey: 'status', header: 'Status' },
  { accessorKey: 'trigger', header: 'Trigger' },
  { accessorKey: 'urlsScanned', header: 'URLs scanned' },
  { accessorKey: 'finishedAt', header: 'Finished' },
  { id: 'actions', header: '' },
];

const statusColor: Record<string, BadgeColor> = {
  queued: 'neutral',
  running: 'info',
  completed: 'success',
  failed: 'error',
};

const triggerScan = async (): Promise<void> => {
  const scan = await scansStore.trigger(domainId.value);
  // The global store action refreshes the unfiltered list — re-fetch with
  // our filter so the new row lands in the right place.
  await scansStore.list(domainId.value);
  toast.add({
    title: `Scan ${scan.status}`,
    description: `${scan.urlsScanned ?? 0} URLs scanned`,
    color: scan.status === 'failed' ? 'error' : 'success',
    icon: scan.status === 'failed' ? 'i-lucide-triangle-alert' : 'i-lucide-check',
  });
};

const formatTime = (iso: string | null): string => {
  if (!iso) return '—';
  return new Date(iso).toLocaleString();
};
</script>

<template>
  <UDashboardNavbar :title="`Scans · ${domain?.fqdn ?? domainId.substring(0, 8)}`">
    <template #leading>
      <UButton
        color="neutral"
        variant="ghost"
        icon="i-lucide-arrow-left"
        :to="`/domains/${domainId}`"
      />
    </template>
    <template #right>
      <UButton
        icon="i-lucide-radar"
        :loading="triggering"
        label="Run scan now"
        @click="triggerScan"
      /></template>
  </UDashboardNavbar>

  <div class="p-4 sm:p-6 lg:p-8 space-y-4">
    <UCard v-if="!loading && scans.length === 0">
      <div class="flex flex-col items-center gap-2 py-12 text-center">
        <UIcon name="i-lucide-radar" class="text-4xl text-gray-400" />
        <h2 class="text-lg font-semibold">No scans yet</h2>
        <p class="text-sm text-gray-500 max-w-md">
          Run the first scan to discover what cookies the site is setting. The scanner crawls
          discovered URLs (apex + sitemap entries) via the Cloudflare browser-rendering worker.
        </p>
        <UButton
          class="mt-2"
          icon="i-lucide-radar"
          label="Run scan now"
          :loading="triggering"
          @click="triggerScan"
        />
      </div>
    </UCard>

    <UTable v-else :data="scans" :columns="columns" :loading="loading" class="w-full">
      <template #createdAt-cell="{ row }">
        <NuxtLink :to="`/scans/${row.original.id}`" class="text-sm hover:underline">
          {{ formatTime(row.original.createdAt) }}
        </NuxtLink>
      </template>
      <template #status-cell="{ row }">
        <UBadge :color="statusColor[row.original.status] ?? 'neutral'" variant="subtle">
          {{ row.original.status }}
        </UBadge>
      </template>
      <template #trigger-cell="{ row }">
        <span class="text-sm">{{ row.original.trigger }}</span>
      </template>
      <template #urlsScanned-cell="{ row }">
        <span class="text-sm tabular-nums">{{ row.original.urlsScanned }}</span>
      </template>
      <template #finishedAt-cell="{ row }">
        <span class="text-sm text-gray-500">{{ formatTime(row.original.finishedAt) }}</span>
      </template>
      <template #actions-cell="{ row }">
        <UButton size="xs" variant="soft" :to="`/scans/${row.original.id}`">View</UButton>
      </template>
    </UTable>
  </div>
</template>
