<script setup lang="ts">
const auth = useAuthStore();

const nav = computed(() => {
  const items: Array<Array<{ label: string; icon: string; to: string }>> = [
    [
      { label: 'Dashboard', icon: 'i-lucide-layout-dashboard', to: '/' },
      { label: 'Domains', icon: 'i-lucide-globe', to: '/domains' },
      { label: 'Scans', icon: 'i-lucide-radar', to: '/scans' },
      { label: 'Consent log', icon: 'i-lucide-file-check', to: '/consent' },
      { label: 'Cookie database', icon: 'i-lucide-database', to: '/cookies' },
    ],
    [
      { label: 'Settings', icon: 'i-lucide-settings', to: '/settings' },
    ],
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

const orgItems = computed(() =>
  auth.organisations.map((org) => ({
    label: org.name,
    onSelect: () => auth.setCurrentOrganisation(org),
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
        <div class="flex items-center gap-2 px-2 py-1">
          <UIcon name="i-lucide-shield-check" class="text-primary-500 text-2xl" />
          <span class="font-semibold">Effect CMP</span>
        </div>
        <UDropdownMenu v-if="auth.currentOrganisation" :items="orgItems" class="mt-2">
          <UButton
            color="neutral"
            variant="ghost"
            class="w-full justify-between"
            :label="auth.currentOrganisation.name"
            trailing-icon="i-lucide-chevrons-up-down"
          />
        </UDropdownMenu>
      </template>

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

    <UDashboardPanel>
      <slot />
    </UDashboardPanel>
  </UDashboardGroup>
</template>
