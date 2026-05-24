<script setup lang="ts">
interface DashboardCounts {
  domains: number;
  scans: number;
  consentEvents: number;
}

const auth = useAuthStore();

const counts = ref<DashboardCounts | null>(null);
const loading = ref(true);
const error = ref<Error | null>(null);

const fetchCounts = async (): Promise<void> => {
  loading.value = true;
  error.value = null;
  try {
    const response = await $api<{ data: DashboardCounts }>('/api/dashboard');
    counts.value = response.data;
  } catch (caught) {
    error.value = caught instanceof Error ? caught : new Error(String(caught));
  } finally {
    loading.value = false;
  }
};

await fetchCounts();

const tiles = computed(() => {
  if (!counts.value) return [];
  return [
    {
      label: 'Domains',
      value: counts.value.domains,
      icon: 'i-lucide-globe',
      to: '/domains',
    },
    {
      label: 'Scans',
      value: counts.value.scans,
      icon: 'i-lucide-radar',
      to: '/scans',
    },
    {
      label: 'Consent events',
      value: counts.value.consentEvents,
      icon: 'i-lucide-file-check',
      to: '/consent',
    },
  ];
});
</script>

<template>
  <UDashboardNavbar :title="`Dashboard · ${auth.currentOrganisation?.name ?? ''}`" />

  <div class="p-4 sm:p-6 lg:p-8 space-y-6">
    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :title="error.message"
    />

    <div v-if="loading" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <USkeleton v-for="i in 3" :key="i" class="h-32 w-full" />
    </div>

    <div v-else class="grid grid-cols-1 sm:grid-cols-3 gap-4">
      <NuxtLink v-for="tile in tiles" :key="tile.label" :to="tile.to" class="group">
        <UCard class="transition-shadow group-hover:shadow-md h-full">
          <div class="flex items-start justify-between">
            <div>
              <p class="text-sm text-gray-500">{{ tile.label }}</p>
              <p class="mt-1 text-3xl font-semibold tabular-nums">{{ tile.value }}</p>
            </div>
            <UIcon :name="tile.icon" class="size-8 text-gray-400" />
          </div>
        </UCard>
      </NuxtLink>
    </div>

    <UCard>
      <template #header>
        <h3 class="font-semibold">Getting started</h3>
      </template>
      <ol class="list-decimal pl-5 space-y-2 text-sm text-gray-600 dark:text-gray-400">
        <li>
          Add a domain on the
          <NuxtLink to="/domains" class="text-primary-500 hover:underline">Domains</NuxtLink> page.
        </li>
        <li>Open the domain's banner config, set colours / copy / layout, hit Publish.</li>
        <li>
          Drop the embed snippet (shown on the domain detail page) into the client site's
          <code>&lt;head&gt;</code>.
        </li>
        <li>
          Trigger a
          <NuxtLink to="/scans" class="text-primary-500 hover:underline">scan</NuxtLink>
          to populate the cookie policy and consent categories.
        </li>
        <li>
          Watch
          <NuxtLink to="/consent" class="text-primary-500 hover:underline">consent events</NuxtLink>
          land as visitors interact with the banner.
        </li>
      </ol>
    </UCard>
  </div>
</template>
