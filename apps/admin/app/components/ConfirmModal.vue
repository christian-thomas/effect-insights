<script setup lang="ts">
import type { ButtonProps } from '@nuxt/ui';

type ButtonColor = NonNullable<ButtonProps['color']>;

/**
 * Shared confirm dialog. Replaces the 9-way duplicate that used to live
 * in each page where window.confirm() was previously called. Owns the
 * UModal markup, footer buttons, and loading state — callers only supply
 * the wording, optional body slot, and a confirm handler that receives
 * the modal's `close` callback (so the handler can dismiss after a
 * successful async call).
 *
 * Pattern:
 *
 *   <ConfirmModal
 *     v-model:open="deleteOpen"
 *     :title="`Remove ${target?.fqdn ?? 'domain'}?`"
 *     description="Consent records will be retained…"
 *     confirm-label="Remove"
 *     confirm-color="error"
 *     confirm-icon="i-lucide-trash-2"
 *     :loading="deleting"
 *     @confirm="confirmDelete"
 *   />
 *
 * The body slot is rendered above the footer for confirms that need to
 * collect input (e.g. mute reason on accessibility-scans/[id].vue).
 */

interface Props {
  open: boolean;
  title: string;
  description?: string;
  confirmLabel?: string;
  confirmColor?: ButtonColor;
  confirmIcon?: string;
  cancelLabel?: string;
  loading?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  description: undefined,
  confirmLabel: 'Confirm',
  confirmColor: 'error',
  confirmIcon: undefined,
  cancelLabel: 'Cancel',
  loading: false,
});

const emit = defineEmits<{
  'update:open': [value: boolean];
  confirm: [close: () => void];
}>();

const openModel = computed<boolean>({
  get: (): boolean => props.open,
  set: (value: boolean): void => emit('update:open', value),
});

const onConfirm = (close: () => void): void => {
  emit('confirm', close);
};
</script>

<template>
  <UModal v-model:open="openModel" :title="title" :description="description">
    <template v-if="$slots.body" #body="{ close }">
      <slot name="body" :close="close" />
    </template>
    <template #footer="{ close }">
      <div class="flex justify-end gap-2 w-full">
        <UButton color="neutral" variant="ghost" :label="cancelLabel" @click="close" />
        <UButton
          :color="confirmColor"
          :icon="confirmIcon"
          :label="confirmLabel"
          :loading="loading"
          @click="onConfirm(close)"
        />
      </div>
    </template>
  </UModal>
</template>
