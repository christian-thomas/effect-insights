<script setup lang="ts">
import type { BadgeProps, TableColumn } from '@nuxt/ui';
import type { ScanFinding } from '~/types/scans';

type BadgeColor = NonNullable<BadgeProps['color']>;

const route = useRoute();
const scansStore = useScansStore();
const { selected: scan, findings, diff, loading } = storeToRefs(scansStore);

const scanId = computed((): string => String(route.params.id));

await scansStore.show(scanId.value);

// Scripts, iframes, and network requests come back with `category = null`
// (only cookies get resolved through CookieCategoryResolver), so they'd
// flood the `__unknown__` bucket and drown the real uncategorised
// cookies. This page is the cookie-centric view; the other finding
// types are persisted for audit/debug but not surfaced here.
const cookieFindings = computed((): ScanFinding[] =>
  findings.value.filter((finding: ScanFinding): boolean => finding.type === 'cookie'),
);

const otherFindingsCount = computed((): number => findings.value.length - cookieFindings.value.length);

const findingsByCategory = computed((): Record<string, ScanFinding[]> => {
  const groups: Record<string, ScanFinding[]> = {};
  for (const finding of cookieFindings.value) {
    const key = finding.category ?? '__unknown__';
    if (!groups[key]) groups[key] = [];
    groups[key].push(finding);
  }
  return groups;
});

const columns: TableColumn<ScanFinding>[] = [
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

const isInFlight = computed((): boolean => scan.value?.status === 'running' || scan.value?.status === 'queued');

const progressPercent = computed((): number => {
  if (!scan.value || !scan.value.urlsDiscovered) return 0;
  return Math.min(100, Math.round((scan.value.urlsScanned / scan.value.urlsDiscovered) * 100));
});

// Poll the scan endpoint while the batch is still running so
// urls_scanned + findings populate live without the operator hitting
// refresh. `silent: true` keeps the table from flashing its loading
// state on every tick.
const POLL_INTERVAL_MS = 2000;
let pollHandle: ReturnType<typeof setInterval> | null = null;

const startPolling = (): void => {
  if (pollHandle !== null) return;
  pollHandle = setInterval((): void => {
    if (!isInFlight.value) {
      stopPolling();
      return;
    }
    void scansStore.show(scanId.value, { silent: true });
  }, POLL_INTERVAL_MS);
};

const stopPolling = (): void => {
  if (pollHandle !== null) {
    clearInterval(pollHandle);
    pollHandle = null;
  }
};

onMounted((): void => {
  if (isInFlight.value) startPolling();
});

onBeforeUnmount(stopPolling);

watch(isInFlight, (running: boolean): void => {
  if (running) startPolling();
  else stopPolling();
});

const toast = useToast();
const retrying = ref(false);

const retryFailed = async (): Promise<void> => {
  if (!scan.value || retrying.value) return;
  retrying.value = true;
  try {
    await scansStore.retryFailed(scan.value.id);
    toast.add({
      title: 'Retrying failed URLs',
      description: 'The scan will resume — progress should pick back up shortly.',
      color: 'success',
      icon: 'i-lucide-refresh-cw',
    });
  } catch (error: unknown) {
    toast.add({
      title: 'Could not retry',
      description: error instanceof Error ? error.message : 'Unknown error',
      color: 'error',
      icon: 'i-lucide-triangle-alert',
    });
  } finally {
    retrying.value = false;
  }
};
</script>

<template>
  <PageHeader
    :title="scan ? `Scan ${scan.id.slice(0, 8)}…` : 'Scan'"
    :description="
      scan
        ? `Status: ${scan.status} · ${scan.urlsScanned}/${scan.urlsDiscovered} URLs scanned · ${cookieFindings.length} cookies`
        : undefined
    "
  />

  <div class="px-4 sm:px-6 lg:px-8 pt-4 pb-6 space-y-4">
    <div v-if="scan">
      <UCard v-if="isInFlight" class="mt-4">
        <div class="flex items-center gap-4">
          <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin text-primary-500" />
          <div class="flex-1">
            <div class="flex items-center justify-between text-sm mb-1">
              <span class="font-medium">
                {{ scan.status === 'queued' ? 'Waiting for worker…' : 'Scanning URLs…' }}
              </span>
              <span class="text-gray-500">
                {{ scan.urlsScanned }} / {{ scan.urlsDiscovered }} ({{ progressPercent }}%)
              </span>
            </div>
            <UProgress :model-value="progressPercent" :max="100" />
          </div>
        </div>
      </UCard>

      <UAlert
        v-if="scan.status === 'partial' && scan.failedUrls.length > 0"
        color="warning"
        variant="soft"
        icon="i-lucide-circle-alert"
        :title="`Partial scan — ${scan.failedUrls.length} URL${scan.failedUrls.length === 1 ? '' : 's'} didn't make it`"
        description="The chunk job hit infra failures it couldn't ride out. Retry just the failed URLs against this scan — successful findings are kept."
        class="mt-4"
      >
        <template #actions>
          <UButton
            icon="i-lucide-refresh-cw"
            :loading="retrying"
            :label="`Retry failed URLs (${scan.failedUrls.length})`"
            @click="retryFailed"
          />
        </template>
      </UAlert>

      <UAlert
        v-else-if="scan.errorLog"
        color="error"
        variant="soft"
        :title="`Scan failed: ${String(scan.errorLog.message ?? 'unknown error')}`"
        :description="scan.failedUrls.length > 0 ? `${scan.failedUrls.length} URL${scan.failedUrls.length === 1 ? '' : 's'} can be retried below.` : undefined"
        class="mt-4"
      >
        <template v-if="scan.failedUrls.length > 0" #actions>
          <UButton
            icon="i-lucide-refresh-cw"
            :loading="retrying"
            :label="`Retry failed URLs (${scan.failedUrls.length})`"
            @click="retryFailed"
          />
        </template>
      </UAlert>

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
          <span class="text-sm text-gray-500">{{ group.length }} cookies</span>
        </div>
        <div class="w-full overflow-x-auto rounded-md border border-gray-200 dark:border-gray-800">
          <UTable
            :data="group"
            :columns="columns"
            :loading="loading"
            :ui="{ td: 'max-w-xs truncate align-top' }"
          />
        </div>
      </div>

      <UAlert
        v-if="cookieFindings.length === 0 && !loading"
        title="No cookies observed"
        description="The scan completed without observing any cookies."
        color="neutral"
        variant="subtle"
        class="mt-4"
      />

      <p v-if="otherFindingsCount > 0" class="mt-6 text-xs text-gray-500">
        {{ otherFindingsCount }} non-cookie findings (scripts, iframes, network requests) are
        persisted for audit but not surfaced here.
      </p>
    </div>
  </div>
</template>
