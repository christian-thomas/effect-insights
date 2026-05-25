<script setup lang="ts">
import type { NavigationMenuItem } from '@nuxt/ui';

const auth = useAuthStore();

const nav = computed((): NavigationMenuItem[][] => {
  const platform: NavigationMenuItem[] = [
    { label: 'Platform', type: 'label' },
    { label: 'Domains', icon: 'i-lucide-globe', to: '/domains' },
  ];

  if (auth.user?.isSuperuser) {
    platform.push({
      label: 'Organisations',
      icon: 'i-lucide-building',
      to: '/organisations',
    });
  }

  const consent: NavigationMenuItem[] = [
    { label: 'Consent management', type: 'label' },
    { label: 'Cookie scans', icon: 'i-lucide-radar', to: '/scans' },
    { label: 'Consent log', icon: 'i-lucide-file-check', to: '/consent' },
    { label: 'Cookie database', icon: 'i-lucide-database', to: '/cookies' },
  ];

  const accessibility: NavigationMenuItem[] = [
    { label: 'Accessibility', type: 'label' },
    { label: 'Accessibility scans', icon: 'i-lucide-accessibility', to: '/accessibility-scans' },
  ];

  return [
    [{ label: 'Dashboard', icon: 'i-lucide-layout-dashboard', to: '/' }],
    platform,
    consent,
    accessibility,
    [{ label: 'Settings', icon: 'i-lucide-settings', to: '/settings' }],
  ];
});

const signOut = async (): Promise<void> => {
  await auth.logout();
  await navigateTo('/login');
};

const userItems = computed(() => [
  [{ label: auth.user?.email ?? '', type: 'label' as const }],
  [{ label: 'Profile', icon: 'i-lucide-user', to: '/profile' }],
  [{ label: 'Sign out', icon: 'i-lucide-log-out', onSelect: signOut }],
]);
</script>

<template>
  <UDashboardGroup>
    <UDashboardSidebar
      :collapsible="false"
      :resizable="false"
      class="bg-gray-50/50 dark:bg-gray-900/50"
    >
      <template #header>
        <div class="flex items-center gap-2 min-w-0">
          <UIcon name="i-lucide-shield-check" class="text-primary-500 text-xl shrink-0" />
          <span class="font-semibold truncate">Effect Insight</span>
        </div>
      </template>

      <!-- The org switcher lives in each page's UDashboardNavbar #right slot
           (see components/OrgSwitcher.vue). It was previously a ghost-button
           dropdown here in the sidebar body, but that gave it no visual
           anchor and made the current tenant hard to spot. Moving it to the
           navbar puts it next to the page title where users actually look
           for "what context am I in?". -->
      <UNavigationMenu :items="nav" orientation="vertical" />

      <template #footer>
        <UDropdownMenu :items="userItems">
          <UButton
            color="neutral"
            variant="ghost"
            class="w-full justify-start"
            :label="auth.user?.name ?? ''"
            icon="i-lucide-user"
          />
        </UDropdownMenu>
      </template>
    </UDashboardSidebar>

    <!-- Use the panel's #body slot — that's where Nuxt UI applies
         `flex-1 overflow-y-auto`. Default slot bypasses scroll entirely.
         Override the body class to strip the built-in padding so pages
         keep control via their own UContainer. -->
    <UDashboardPanel :ui="{ body: 'flex flex-col flex-1 overflow-y-auto' }">
      <template #body>
        <!-- Key-based remount: every page's onMounted re-fires on org switch. -->
        <div :key="auth.organisationCacheKey" class="contents">
          <slot />
        </div>
      </template>
    </UDashboardPanel>
  </UDashboardGroup>
</template>
