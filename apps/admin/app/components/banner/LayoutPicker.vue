<script setup lang="ts">
import type { BannerLayout } from '@effectinsight/types/schemas/banner';

const modelValue = defineModel<BannerLayout>({ required: true });

const layouts: ReadonlyArray<{
  value: BannerLayout;
  label: string;
  description: string;
  icon: string;
}> = [
  {
    value: 'modal',
    label: 'Modal',
    description: 'Center-screen, blocks the page until consent.',
    icon: 'i-lucide-square',
  },
  {
    value: 'bar-top',
    label: 'Bar (top)',
    description: 'Slim banner at the top of the viewport.',
    icon: 'i-lucide-panel-top',
  },
  {
    value: 'bar-bottom',
    label: 'Bar (bottom)',
    description: 'Slim banner at the bottom of the viewport.',
    icon: 'i-lucide-panel-bottom',
  },
  {
    value: 'corner',
    label: 'Corner',
    description: 'Compact card in the bottom-right, non-blocking.',
    icon: 'i-lucide-rectangle-horizontal',
  },
];
</script>

<template>
  <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
    <button
      v-for="option in layouts"
      :key="option.value"
      type="button"
      class="flex items-start gap-3 rounded-lg border p-4 text-left transition-colors"
      :class="
        modelValue === option.value
          ? 'border-primary-500 bg-primary-50 dark:bg-primary-950/30'
          : 'border-gray-200 dark:border-gray-800 hover:border-gray-300 dark:hover:border-gray-700'
      "
      @click="modelValue = option.value"
    >
      <UIcon :name="option.icon" class="size-6 text-gray-500 mt-0.5" />
      <div class="flex-1">
        <div class="font-medium">{{ option.label }}</div>
        <div class="text-xs text-gray-500 mt-0.5">{{ option.description }}</div>
      </div>
      <UIcon
        v-if="modelValue === option.value"
        name="i-lucide-check"
        class="size-5 text-primary-500"
      />
    </button>
  </div>
</template>
