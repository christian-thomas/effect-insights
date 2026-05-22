<script setup lang="ts">
const route = useRoute();
</script>

<template>
  <StubPage
    :title="`Domain · ${route.params.id}`"
    description="Domain overview: status, last scan summary, embed snippet, recent consent stats."
  />
</template>
