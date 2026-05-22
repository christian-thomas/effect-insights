<script setup lang="ts">
const route = useRoute();
</script>

<template>
  <StubPage
    :title="`Banner config · ${route.params.id}`"
    description="Edit colours, copy, granularity, locales, geo-rules, and GCM defaults. Saving creates a new banner_configs version and publishes to KV."
  />
</template>
