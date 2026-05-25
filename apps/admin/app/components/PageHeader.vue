<script setup lang="ts">
defineProps<{
  title: string;
  description?: string;
}>();
</script>

<template>
  <UDashboardNavbar
    :ui="{
      root: 'h-auto min-h-(--ui-header-height) py-3 shrink-0 flex items-center justify-between border-b border-default px-4 sm:px-6 gap-1.5',
    }"
  >
    <template #title>
      <div class="flex flex-col min-w-0">
        <span class="font-semibold text-highlighted truncate">{{ title }}</span>
        <span
          v-if="description || !!$slots.description"
          class="text-xs text-gray-500 dark:text-gray-400 font-normal mt-0.5"
        >
          <slot name="description">{{ description }}</slot>
        </span>
      </div>
    </template>
    <template v-if="!!$slots.right" #right>
      <slot name="right" />
    </template>
    <template v-if="!!$slots.trailing" #trailing>
      <slot name="trailing" />
    </template>
  </UDashboardNavbar>
</template>
