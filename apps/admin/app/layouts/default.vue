<script setup lang="ts">
const auth = useAuthStore();
const toast = useToast();

const nav = computed(() => {
  const items: Array<Array<{ label: string; icon: string; to: string }>> = [
    [
      { label: 'Dashboard', icon: 'i-lucide-layout-dashboard', to: '/' },
      { label: 'Domains', icon: 'i-lucide-globe', to: '/domains' },
      { label: 'Scans', icon: 'i-lucide-radar', to: '/scans' },
      { label: 'Consent log', icon: 'i-lucide-file-check', to: '/consent' },
      { label: 'Cookie database', icon: 'i-lucide-database', to: '/cookies' },
    ],
    [{ label: 'Settings', icon: 'i-lucide-settings', to: '/settings' }],
  ];

  if (auth.user?.isSuperuser) {
    items[0]!.splice(1, 0, {
      label: 'Organisations',
      icon: 'i-lucide-building',
      to: '/organisations',
    });
  }

  return items;
});

const switchTo = async (organisationId: string): Promise<void> => {
  try {
    await auth.switchOrganisation(organisationId);
  } catch (caught) {
    toast.add({
      color: 'error',
      icon: 'i-lucide-triangle-alert',
      title: 'Could not switch organisation',
      description: caught instanceof Error ? caught.message : 'Please try again',
    });
  }
};

const orgItems = computed(() =>
  auth.organisations.map((organisation) => ({
    label: organisation.name,
    onSelect: () => switchTo(organisation.id),
  })),
);

const signOut = async (): Promise<void> => {
  await auth.logout();
  await navigateTo('/login');
};

const userItems = computed(() => [
  [{ label: auth.user?.email ?? '', type: 'label' as const }],
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
          <span class="font-semibold truncate">Effect CMP</span>
        </div>
      </template>

      <!-- Org switcher sits in the sidebar body — the header slot is fixed-
           height (--ui-header-height) so brand + dropdown would crash there. -->
      <UDropdownMenu
        v-if="auth.currentOrganisation"
        :items="orgItems"
        :ui="{ content: 'w-(--reka-dropdown-menu-trigger-width)' }"
        class="w-full"
      >
        <UButton
          color="neutral"
          variant="ghost"
          block
          class="justify-between"
          :label="auth.currentOrganisation.name"
          trailing-icon="i-lucide-chevrons-up-down"
        />
      </UDropdownMenu>

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
