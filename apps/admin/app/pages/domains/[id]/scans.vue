<script setup lang="ts">
const route = useRoute();
</script>

<template>
  <StubPage
    :title="`Scans · ${route.params.id}`"
    description="Scan history for this domain. Trigger a manual scan or open a finished scan to review cookies/scripts detected."
  />
</template>
