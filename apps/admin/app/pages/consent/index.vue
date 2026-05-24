<script setup lang="ts">
import type { TableColumn, DropdownMenuItem } from '@nuxt/ui';
import type { ConsentEvent, ConsentEventType } from '~/types/consent';

const consentStore = useConsentEventsStore();
const domainsStore = useDomainsStore();
const { items: events, pagination, loading, error } = storeToRefs(consentStore);
const runtimeConfig = useRuntimeConfig();

const downloadReceipt = async (path: string, fallbackName: string): Promise<void> => {
  const url = `${runtimeConfig.public.apiBase}${path}`;
  const response = await fetch(url, { credentials: 'include', headers: { Accept: '*/*' } });
  if (!response.ok) {
    throw new Error(`Receipt download failed (${response.status})`);
  }
  const blob = await response.blob();
  const disposition = response.headers.get('Content-Disposition') ?? '';
  const filenameMatch = disposition.match(/filename="?([^";]+)"?/);
  const filename = filenameMatch?.[1] ?? fallbackName;
  const objectUrl = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = objectUrl;
  anchor.download = filename;
  document.body.appendChild(anchor);
  anchor.click();
  document.body.removeChild(anchor);
  URL.revokeObjectURL(objectUrl);
};

await domainsStore.list();

const filters = reactive<{
  domainId: string | undefined;
  anonymousId: string;
  eventType: ConsentEventType | undefined;
  from: string;
  to: string;
}>({
  domainId: undefined,
  anonymousId: '',
  eventType: undefined,
  from: '',
  to: '',
});

const currentPage = ref(1);

const fetchEvents = async (): Promise<void> => {
  await consentStore.list({
    domainId: filters.domainId,
    anonymousId: filters.anonymousId || undefined,
    eventType: filters.eventType,
    from: filters.from || undefined,
    to: filters.to || undefined,
    page: currentPage.value,
    perPage: 25,
  });
};

await fetchEvents();

const applyFilters = async (): Promise<void> => {
  currentPage.value = 1;
  await fetchEvents();
};

const clearFilters = async (): Promise<void> => {
  filters.domainId = undefined;
  filters.anonymousId = '';
  filters.eventType = undefined;
  filters.from = '';
  filters.to = '';
  currentPage.value = 1;
  await fetchEvents();
};

watch(currentPage, fetchEvents);

const domainFqdn = (id: string): string => {
  const domain = domainsStore.findById(id);
  return domain?.fqdn ?? id.substring(0, 8);
};

const eventColor = (type: ConsentEventType): 'success' | 'error' | 'warning' | 'neutral' => {
  if (type === 'granted') return 'success';
  if (type === 'denied') return 'error';
  if (type === 'changed') return 'warning';
  return 'neutral';
};

const formatTime = (iso: string): string => {
  return new Date(iso).toLocaleString();
};

const detailOpen = ref(false);
const selected = ref<ConsentEvent | null>(null);

const openDetail = (event: ConsentEvent): void => {
  selected.value = event;
  detailOpen.value = true;
};

const eventReceiptMenu = (event: ConsentEvent): DropdownMenuItem[] => [
  {
    label: 'JSON (signed)',
    icon: 'i-lucide-braces',
    onSelect: (): void => {
      void downloadReceipt(
        `/api/consent-events/${event.id}/receipt?format=json`,
        `consent-receipt-${event.id}.json`,
      );
    },
  },
  {
    label: 'PDF',
    icon: 'i-lucide-file-text',
    onSelect: (): void => {
      void downloadReceipt(
        `/api/consent-events/${event.id}/receipt?format=pdf`,
        `consent-receipt-${event.id}.pdf`,
      );
    },
  },
];

const canBundleByVisitor = computed<boolean>(
  (): boolean => Boolean(filters.anonymousId) && Boolean(filters.domainId),
);

const visitorReceiptMenu = computed<DropdownMenuItem[]>((): DropdownMenuItem[] => {
  const anonymousId = filters.anonymousId;
  const domainId = filters.domainId;
  if (!anonymousId || !domainId) return [];
  const baseQuery = `anonymous_id=${encodeURIComponent(anonymousId)}&domain_id=${encodeURIComponent(domainId)}`;
  return [
    {
      label: 'JSON (signed)',
      icon: 'i-lucide-braces',
      onSelect: (): void => {
        void downloadReceipt(
          `/api/consent-events/receipts/by-anonymous-id?${baseQuery}&format=json`,
          `consent-receipt-visitor-${anonymousId}.json`,
        );
      },
    },
    {
      label: 'PDF',
      icon: 'i-lucide-file-text',
      onSelect: (): void => {
        void downloadReceipt(
          `/api/consent-events/receipts/by-anonymous-id?${baseQuery}&format=pdf`,
          `consent-receipt-visitor-${anonymousId}.pdf`,
        );
      },
    },
  ];
});

const columns: TableColumn<ConsentEvent>[] = [
  { accessorKey: 'occurredAt', header: 'When' },
  { accessorKey: 'domainId', header: 'Domain' },
  { accessorKey: 'eventType', header: 'Event' },
  { accessorKey: 'anonymousId', header: 'Visitor' },
  { accessorKey: 'categories', header: 'Categories' },
  { accessorKey: 'countryCode', header: 'Country' },
];

const categoryDots = (categories: ConsentEvent['categories']): string => {
  const order: Array<keyof typeof categories> = [
    'necessary',
    'preferences',
    'statistics',
    'marketing',
  ];
  return order.map((category) => (categories[category] ? '●' : '○')).join(' ');
};
</script>

<template>
  <UDashboardNavbar title="Consent log" />

  <div class="p-4 sm:p-6 lg:p-8 space-y-4">
    <UCard>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3">
        <USelect
          v-model="filters.domainId"
          placeholder="All domains"
          :items="[
            { label: 'All domains', value: undefined },
            ...domainsStore.items.map((domain) => ({ label: domain.fqdn, value: domain.id })),
          ]"
        />
        <USelect
          v-model="filters.eventType"
          placeholder="All event types"
          :items="[
            { label: 'All events', value: undefined },
            { label: 'Granted', value: 'granted' },
            { label: 'Denied', value: 'denied' },
            { label: 'Changed', value: 'changed' },
            { label: 'Withdrawn', value: 'withdrawn' },
          ]"
        />
        <UInput v-model="filters.anonymousId" placeholder="Anonymous ID" icon="i-lucide-search" />
        <UInput v-model="filters.from" type="date" placeholder="From" />
        <UInput v-model="filters.to" type="date" placeholder="To" />
      </div>
      <div class="flex justify-end gap-2 mt-3">
        <UDropdownMenu v-if="canBundleByVisitor" :items="visitorReceiptMenu">
          <UButton
            icon="i-lucide-download"
            color="neutral"
            variant="soft"
            label="Download visitor receipt"
          />
        </UDropdownMenu>
        <UButton color="neutral" variant="ghost" label="Clear" @click="clearFilters" />
        <UButton icon="i-lucide-filter" label="Apply" :loading="loading" @click="applyFilters" />
      </div>
    </UCard>

    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :title="error.message"
    />

    <UCard v-if="!loading && events.length === 0">
      <div class="flex flex-col items-center gap-2 py-12 text-center">
        <UIcon name="i-lucide-file-check" class="text-4xl text-gray-400" />
        <h2 class="text-lg font-semibold">No consent events yet</h2>
        <p class="text-sm text-gray-500 max-w-md">
          Events arrive as visitors interact with the banner on a published domain. Embed the
          snippet on a client site and accept consent to see entries land here.
        </p>
      </div>
    </UCard>

    <UTable v-else :data="events" :columns="columns" :loading="loading" class="w-full">
      <template #occurredAt-cell="{ row }">
        <button class="text-sm hover:underline" @click="openDetail(row.original)">
          {{ formatTime(row.original.occurredAt) }}
        </button>
      </template>
      <template #domainId-cell="{ row }">
        <code class="text-xs">{{ domainFqdn(row.original.domainId) }}</code>
      </template>
      <template #eventType-cell="{ row }">
        <UBadge :color="eventColor(row.original.eventType)" variant="subtle">
          {{ row.original.eventType }}
        </UBadge>
      </template>
      <template #anonymousId-cell="{ row }">
        <code class="text-xs">{{ row.original.anonymousId.substring(0, 14) }}…</code>
      </template>
      <template #categories-cell="{ row }">
        <code
          class="font-mono text-sm"
          :title="`necessary / preferences / statistics / marketing — ● granted, ○ denied`"
        >
          {{ categoryDots(row.original.categories) }}
        </code>
      </template>
      <template #countryCode-cell="{ row }">
        {{ row.original.countryCode ?? '—' }}
      </template>
    </UTable>

    <div v-if="pagination.lastPage > 1" class="flex justify-between items-center">
      <p class="text-xs text-gray-500">
        {{ pagination.total.toLocaleString() }} event{{ pagination.total === 1 ? '' : 's' }} · page
        {{ pagination.currentPage }} of {{ pagination.lastPage }}
      </p>
      <UPagination
        v-model:page="currentPage"
        :total="pagination.total"
        :items-per-page="pagination.perPage"
      />
    </div>

    <USlideover
      v-model:open="detailOpen"
      :title="selected ? `Consent event ${selected.id.substring(0, 8)}…` : ''"
    >
      <template #body>
        <div v-if="selected" class="space-y-4 text-sm">
          <div class="flex justify-end">
            <UDropdownMenu :items="eventReceiptMenu(selected)">
              <UButton
                icon="i-lucide-download"
                color="neutral"
                variant="soft"
                label="Download receipt"
              />
            </UDropdownMenu>
          </div>

          <section>
            <h3 class="font-semibold mb-2">Event</h3>
            <dl class="grid grid-cols-3 gap-2">
              <dt class="text-gray-500">Type</dt>
              <dd class="col-span-2">
                <UBadge :color="eventColor(selected.eventType)" variant="subtle">
                  {{ selected.eventType }}
                </UBadge>
              </dd>
              <dt class="text-gray-500">Occurred</dt>
              <dd class="col-span-2">{{ formatTime(selected.occurredAt) }}</dd>
              <dt class="text-gray-500">Domain</dt>
              <dd class="col-span-2 font-mono text-xs">{{ domainFqdn(selected.domainId) }}</dd>
              <dt class="text-gray-500">Banner version</dt>
              <dd class="col-span-2">v{{ selected.bannerConfigVersion }}</dd>
              <dt class="text-gray-500">Country</dt>
              <dd class="col-span-2">{{ selected.countryCode ?? '—' }}</dd>
            </dl>
          </section>

          <section>
            <h3 class="font-semibold mb-2">Visitor</h3>
            <dl class="grid grid-cols-3 gap-2 font-mono text-xs break-all">
              <dt class="text-gray-500 font-sans">Anonymous ID</dt>
              <dd class="col-span-2">{{ selected.anonymousId }}</dd>
              <dt class="text-gray-500 font-sans">UA hash</dt>
              <dd class="col-span-2">{{ selected.userAgentHash }}</dd>
              <dt class="text-gray-500 font-sans">IP hash</dt>
              <dd class="col-span-2">{{ selected.ipHash }}</dd>
            </dl>
          </section>

          <section>
            <h3 class="font-semibold mb-2">Categories</h3>
            <ul class="space-y-1">
              <li
                v-for="key in ['necessary', 'preferences', 'statistics', 'marketing'] as const"
                :key="key"
                class="flex justify-between"
              >
                <span class="capitalize">{{ key }}</span>
                <UBadge :color="selected.categories[key] ? 'success' : 'neutral'" variant="subtle">
                  {{ selected.categories[key] ? 'granted' : 'denied' }}
                </UBadge>
              </li>
            </ul>
          </section>

          <section>
            <h3 class="font-semibold mb-2">GCM v2 signals</h3>
            <dl class="grid grid-cols-2 gap-1 text-xs">
              <template v-for="(value, key) in selected.gcmSignals" :key="key">
                <dt class="text-gray-500 font-mono">{{ key }}</dt>
                <dd>
                  <UBadge :color="value === 'granted' ? 'success' : 'neutral'" variant="subtle">
                    {{ value }}
                  </UBadge>
                </dd>
              </template>
            </dl>
          </section>

          <section>
            <h3 class="font-semibold mb-2">Proof</h3>
            <dl class="grid grid-cols-3 gap-2 font-mono text-xs break-all">
              <dt class="text-gray-500 font-sans">Event UUID</dt>
              <dd class="col-span-2">{{ selected.id }}</dd>
              <dt class="text-gray-500 font-sans">Banner text hash</dt>
              <dd class="col-span-2">{{ selected.bannerTextHash }}</dd>
            </dl>
          </section>
        </div>
      </template>
    </USlideover>
  </div>
</template>
