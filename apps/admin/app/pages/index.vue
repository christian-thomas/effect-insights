<script setup lang="ts">
interface DashboardCounts {
  domains: number;
  cookieScans: number;
  consentEvents: number;
  accessibilityScans: number;
}

interface DashboardTile {
  label: string;
  value: number;
  icon: string;
  to: string;
}

interface DashboardSection {
  heading: string;
  description: string;
  tiles: DashboardTile[];
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

const sections = computed((): DashboardSection[] => {
  const current = counts.value;
  if (!current) return [];
  return [
    {
      heading: 'Platform',
      description: 'Shared across both modules.',
      tiles: [
        {
          label: 'Domains',
          value: current.domains,
          icon: 'i-lucide-globe',
          to: '/domains',
        },
      ],
    },
    {
      heading: 'Consent management',
      description: 'Cookie scanner + consent banner + receipts.',
      tiles: [
        {
          label: 'Cookie scans',
          value: current.cookieScans,
          icon: 'i-lucide-radar',
          to: '/scans',
        },
        {
          label: 'Consent events',
          value: current.consentEvents,
          icon: 'i-lucide-file-check',
          to: '/consent',
        },
      ],
    },
    {
      heading: 'Accessibility scanning',
      description: 'Scheduled axe-core audits + impact-thresholded alerts.',
      tiles: [
        {
          label: 'Accessibility scans',
          value: current.accessibilityScans,
          icon: 'i-lucide-accessibility',
          to: '/accessibility-scans',
        },
      ],
    },
  ];
});
</script>

<template>
  <UDashboardNavbar :title="`Dashboard · ${auth.currentOrganisation?.name ?? ''}`" />

  <div class="p-4 sm:p-6 lg:p-8 space-y-8">
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

    <section v-for="section in sections" v-else :key="section.heading" class="space-y-3">
      <div>
        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">
          {{ section.heading }}
        </h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
          {{ section.description }}
        </p>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <NuxtLink v-for="tile in section.tiles" :key="tile.label" :to="tile.to" class="group">
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
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <UCard>
        <template #header>
          <h3 class="font-semibold">Set up consent management</h3>
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
            <NuxtLink to="/consent" class="text-primary-500 hover:underline">consent events</NuxtLink>
            land as visitors interact with the banner.
          </li>
        </ol>
      </UCard>

      <UCard>
        <template #header>
          <h3 class="font-semibold">Set up accessibility scanning</h3>
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
