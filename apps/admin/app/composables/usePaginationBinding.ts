import type { Ref } from 'vue';
import type { Pagination } from '~/types/pagination';

/**
 * Pure helper that produces a writable `computed<number>` bound to a
 * Pinia-owned pagination ref. The getter reads the store's authoritative
 * current page; the setter fires the supplied list action, which is
 * responsible for updating the store on success.
 *
 * Why a writable computed rather than `v-model="ref" + watch(ref, list)`
 * — when the list action itself resets to page 1 (e.g. after a search
 * change, after a successful trigger() or upload), a plain ref + watcher
 * would double-fire: once from the user's click, once when the store
 * resets the page to 1 and the watcher reacts to the ref drifting back.
 * A writable computed has no internal ref to drift, so the setter is the
 * single source of side-effects.
 */
export function usePaginationBinding(
  pagination: Ref<Pagination>,
  list: (page: number) => Promise<void> | void,
) {
  return computed<number>({
    get: (): number => pagination.value.currentPage,
    set: (page: number): void => {
      void list(page);
    },
  });
}
