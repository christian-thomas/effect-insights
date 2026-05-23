<script setup lang="ts">
const modelValue = defineModel<string>({ required: true });

defineProps<{
  label: string;
  name: string;
}>();
</script>

<template>
  <UFormField :label="label" :name="name">
    <div class="flex items-center gap-2">
      <input
        v-model="modelValue"
        type="color"
        class="size-10 rounded border border-gray-300 dark:border-gray-700 bg-transparent cursor-pointer"
      />
      <UInput
        v-model="modelValue"
        class="flex-1 font-mono"
        placeholder="#000000"
        autocomplete="off"
      />
      <div
        class="size-10 rounded border border-gray-300 dark:border-gray-700"
        :style="{ backgroundColor: modelValue }"
        aria-hidden="true"
      />
    </div>
  </UFormField>
</template>
