/**
 * Shared pagination shapes used by every paginated Pinia store.
 *
 * Laravel pagination meta arrives as snake_case under `meta`; this module
 * canonicalises it into a camelCase {@link Pagination} object so the rest
 * of the UI never has to think about the wire format. Stores stay thin —
 * they list, hydrate, and expose state — and pages read pagination via
 * `storeToRefs` like any other piece of state.
 */

export interface PaginationMeta {
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta?: PaginationMeta;
}

export interface Pagination {
  total: number;
  perPage: number;
  currentPage: number;
  lastPage: number;
}

export const emptyPagination = (perPage: number = 25): Pagination => ({
  total: 0,
  perPage,
  currentPage: 1,
  lastPage: 1,
});

/**
 * Build a {@link Pagination} from a Laravel paginated response. When the
 * endpoint returns an unpaginated `{ data }` payload (no meta block) we
 * synthesise a single-page result so the UI's "page X of Y" + UPagination
 * stays consistent rather than blowing up on null reads.
 */
export const metaToPagination = <T>(
  response: PaginatedResponse<T>,
  fallbackPerPage: number = 25,
): Pagination => ({
  total: response.meta?.total ?? response.data.length,
  perPage: response.meta?.per_page ?? fallbackPerPage,
  currentPage: response.meta?.current_page ?? 1,
  lastPage: response.meta?.last_page ?? 1,
});
