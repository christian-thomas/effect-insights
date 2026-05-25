<script setup lang="ts">
interface DashboardCounts {
  domains: number;
  cookieScans: number;
  consentEvents: number;
  accessibilityScans: number;
}

type ModuleKey = 'platform' | 'consent' | 'accessibility';

interface DashboardTile {
  label: string;
  value: number;
  icon: string;
  to: string;
  module: ModuleKey;
  moduleLabel: string;
}

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

const tiles = computed((): DashboardTile[] => {
  const current = counts.value;
  if (!current) return [];
  return [
    {
      label: 'Domains',
      value: current.domains,
      icon: 'i-lucide-globe',
      to: '/domains',
      module: 'platform',
      moduleLabel: 'Platform',
    },
    {
      label: 'Cookie scans',
      value: current.cookieScans,
      icon: 'i-lucide-radar',
      to: '/scans',
      module: 'consent',
      moduleLabel: 'Consent',
    },
    {
      label: 'Consent events',
      value: current.consentEvents,
      icon: 'i-lucide-file-check',
      to: '/consent',
      module: 'consent',
      moduleLabel: 'Consent',
    },
    {
      label: 'Accessibility scans',
      value: current.accessibilityScans,
      icon: 'i-lucide-accessibility',
      to: '/accessibility-scans',
      module: 'accessibility',
      moduleLabel: 'Accessibility',
    },
  ];
});

const moduleStyles: Record<
  ModuleKey,
  { iconClass: string; badgeClass: string; accentClass: string }
> = {
  platform: {
    iconClass: 'text-gray-500 dark:text-gray-400',
    badgeClass: 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
    accentClass: 'bg-gray-300 dark:bg-gray-700',
  },
  consent: {
    iconClass: 'text-emerald-600 dark:text-emerald-400',
    badgeClass: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    accentClass: 'bg-emerald-500',
  },
  accessibility: {
    iconClass: 'text-violet-600 dark:text-violet-400',
    badgeClass: 'bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300',
    accentClass: 'bg-violet-500',
  },
};
</script>

<template>
  <UDashboardNavbar title="Dashboard"></UDashboardNavbar>

  <div class="p-4 sm:p-6 lg:p-8 space-y-6">
    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      icon="i-lucide-triangle-alert"
      :title="error.message"
    />

    <div v-if="loading" class="grid grid-cols-2 lg:grid-cols-4 gap-4">
      <USkeleton v-for="i in 4" :key="i" class="h-32 w-full" />
    </div>

    <div v-else class="grid grid-cols-2 lg:grid-cols-4 gap-4">
      <NuxtLink v-for="tile in tiles" :key="tile.label" :to="tile.to" class="group">
        <UCard
          class="relative h-full overflow-hidden transition-all group-hover:shadow-md group-hover:-translate-y-0.5"
          :ui="{ body: 'p-5' }"
        >
          <div :class="['absolute inset-x-0 top-0 h-1', moduleStyles[tile.module].accentClass]" />

          <div class="flex items-start justify-between gap-3">
            <UIcon
              :name="tile.icon"
              :class="['size-9 shrink-0', moduleStyles[tile.module].iconClass]"
            />
            <span
              :class="[
                'text-[10px] font-semibold uppercase tracking-wider px-2 py-0.5 rounded-full',
                moduleStyles[tile.module].badgeClass,
              ]"
            >
              {{ tile.moduleLabel }}
            </span>
          </div>

          <p class="mt-4 text-4xl font-semibold tabular-nums">{{ tile.value }}</p>
          <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ tile.label }}</p>
        </UCard>
      </NuxtLink>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <UCard>
        <template #header>
          <div class="flex items-center gap-2">
            <span class="inline-block size-2 rounded-full bg-emerald-500" />
            <h3 class="font-semibold">Set up consent management</h3>
          </div>
        </template>
        <ol class="list-decimal pl-5 space-y-2 text-sm text-gray-600 dark:text-gray-400">
          <li>
            Add a domain on the
            <NuxtLink to="/domains" class="text-primary-500 hover:underline">Domains</NuxtLink>
            page.
          </li>
          <li>Open the domain's banner config, set colours / copy / layout, hit Publish.</li>
          <li>
            Drop the embed snippet (shown on the domain detail page) into the client site's
            <code>&lt;head&gt;</code>.
          </li>
          <li>
            Trigger a
            <NuxtLink to="/scans" class="text-primary-500 hover:underline">cookie scan</NuxtLink>
            to populate the cookie policy and consent categories.
          </li>
          <li>
            Watch
            <NuxtLink to="/consent" class="text-primary-500 hover:underline"
              >consent events</NuxtLink
            >
            land as visitors interact with the banner.
          </li>
        </ol>
      </UCard>

      <UCard>
        <template #header>
          <div class="flex items-center gap-2">
            <span class="inline-block size-2 rounded-full bg-violet-500" />
            <h3 class="font-semibold">Set up accessibility scanning</h3>
          </div>
        </template>
        <ol class="list-decimal pl-5 space-y-2 text-sm text-gray-600 dark:text-gray-400">
          <li>
            Add (or re-use) a domain on the
            <NuxtLink to="/domains" class="text-primary-500 hover:underline">Domains</NuxtLink>
            page. The banner script isn't required for accessibility scans.
          </li>
          <li>
            On the domain detail page set <strong>Accessibility scan cadence</strong> to Weekly or
            Monthly. Default is Never (opt-in).
          </li>
          <li>
            Either wait for the next daily scheduler run, or click
            <strong>Run scan now</strong> from the
            <NuxtLink to="/accessibility-scans" class="text-primary-500 hover:underline">
              Accessibility scans
            </NuxtLink>
            page to trigger one immediately.
          </li>
          <li>
            Review findings grouped by impact (Critical / Serious / Moderate / Minor); mute
            false-positive rules per-domain from the same page.
          </li>
          <li>
            Configure Slack + email recipients on the organisation so future violations alert
            automatically.
          </li>
        </ol>
      </UCard>
    </div>
  </div>
</template>
