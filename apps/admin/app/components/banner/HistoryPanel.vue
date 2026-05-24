<script setup lang="ts">
import type { BannerConfig } from '~/types/banner';

const props = defineProps<{
  history: BannerConfig[];
  loading: boolean;
  rollingBackVersion: number | null;
}>();

const emit = defineEmits<{
  (event: 'rollback', version: number): void;
}>();

const formatTimestamp = (iso: string | null): string => {
  if (!iso) return '—';
  return new Date(iso).toLocaleString();
};

const onRollback = (version: number): void => {
  emit('rollback', version);
};

const currentPublished = computed(
  (): BannerConfig | undefined => props.history.find((entry) => entry.isPublished),
);
</script>

<template>
  <UCard>
    <template #header>
      <div class="flex items-center justify-between">
        <h3 class="font-semibold">Version history</h3>
        <p v-if="history.length > 0" class="text-xs text-gray-500">
          {{ history.length }} published version{{ history.length === 1 ? '' : 's' }}
        </p>
      </div>
    </template>

    <USkeleton v-if="loading && history.length === 0" class="h-24 w-full" />

    <p v-else-if="history.length === 0" class="text-sm text-gray-500 py-2">
      No published versions yet. Once you publish, every version will be listed here so you can
      browse + roll back.
    </p>

    <ul v-else class="divide-y divide-gray-200 dark:divide-gray-800">
      <li
        v-for="entry in history"
        :key="entry.id"
        class="flex items-start gap-3 py-3"
      >
        <div class="flex flex-col items-center gap-1 min-w-[3rem]">
          <UBadge :color="entry.isPublished ? 'success' : 'neutral'" variant="subtle">
            v{{ entry.version }}
          </UBadge>
          <span
            v-if="entry.materialVersion > 0"
            class="text-[10px] text-gray-400"
            title="Material version. Bumps only on material changes — re-prompted visitors are pinned to this."
          >
            mv{{ entry.materialVersion }}
          </span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm">
            <span v-if="entry.isPublished" class="font-semibold">Currently live</span>
            <span v-else class="text-gray-500">Archived</span>
          </p>
          <p class="text-xs text-gray-500 font-mono">
            Published {{ formatTimestamp(entry.publishedAt) }}
          </p>
          <p class="text-xs text-gray-500">
            {{ entry.layout }} · {{ entry.granularity ?? 'categorised' }} ·
            {{ entry.localesEnabled.length }} locale{{
              entry.localesEnabled.length === 1 ? '' : 's'
            }}
          </p>
        </div>
        <UButton
          v-if="!entry.isPublished"
          size="xs"
          color="neutral"
          variant="soft"
          icon="i-lucide-rotate-ccw"
          label="Roll back"
          :loading="rollingBackVersion === entry.version"
          :disabled="rollingBackVersion !== null"
          @click="onRollback(entry.version)"
        />
        <UBadge
          v-else-if="currentPublished && entry.id === currentPublished.id"
          color="success"
          variant="solid"
        >
          Live
        </UBadge>
      </li>
    </ul>

    <p class="text-xs text-gray-500 mt-3">
      Rolling back loads the historic version into the draft — it doesn't publish. Review the
      pending changes panel and click <strong>Publish</strong> to make the rollback live.
    </p>
  </UCard>
</template>
