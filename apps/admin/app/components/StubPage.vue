<script setup lang="ts">
defineProps<{
  title: string;
  description?: string;
}>();
</script>

<template>
  <UDashboardNavbar :title="title" />
  <UDashboardPanelContent>
    <UPageHeader :title="title" :description="description" />
    <UAlert
      class="mt-6"
      color="info"
      variant="subtle"
      icon="i-lucide-construction"
      title="TODO: wire to API in iteration 3"
      description="This page is a scaffold placeholder — the IA is in place, Laravel endpoints + data binding land in the next iteration."
    />
  </UDashboardPanelContent>
</template>
