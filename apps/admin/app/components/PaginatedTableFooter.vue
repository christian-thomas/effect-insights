<script setup lang="ts">
import type { Pagination } from '~/types/pagination';

/**
 * Footer for any paginated table. Renders the `total · page X of Y`
 * caption alongside a UPagination control bound to the caller's
 * writable `page` ref (typically built via `usePaginationBinding`).
 *
 * Hidden when there's only one page — the caller doesn't need a
 * v-if at the call site.
 *
 *   <PaginatedTableFooter
 *     v-model:page="currentPage"
 *     :pagination="pagination"
 *     noun="scan"
 *   />
 */

interface Props {
  pagination: Pagination;
  page: number;
  /** Singular noun for the count caption — pluralised internally. */
  noun: string;
  /**
   * Override pluralisation when an `s` suffix isn't right (e.g. "entry"
   * → "entries"). Leave blank to use `${noun}s` for >1.
   */
  pluralNoun?: string;
}

const props = withDefaults(defineProps<Props>(), {
  pluralNoun: undefined,
});

const emit = defineEmits<{
  'update:page': [value: number];
}>();

const pageModel = computed<number>({
  get: (): number => props.page,
  set: (value: number): void => emit('update:page', value),
});

const caption = computed<string>((): string => {
  const { total, currentPage, lastPage } = props.pagination;
  const noun = total === 1 ? props.noun : (props.pluralNoun ?? `${props.noun}s`);
  return `${total.toLocaleString()} ${noun} · page ${currentPage} of ${lastPage}`;
});
</script>

<template>
  <div v-if="pagination.lastPage > 1" class="flex justify-between items-center">
    <p class="text-xs text-gray-500">{{ caption }}</p>
    <UPagination
      v-model:page="pageModel"
      :total="pagination.total"
      :items-per-page="pagination.perPage"
    />
  </div>
</template>
