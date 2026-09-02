/**
 * The collection envelope of `docs/contracts/http-api-v1.md` section 1.
 *
 * Single resources are *not* wrapped in `data`; they come back under a named
 * top-level key (`feedback`, `integration`), so those shapes are declared next
 * to the service that reads them rather than here.
 */
export interface PaginationMeta {
  readonly current_page: number;
  readonly per_page: number;
  readonly total: number;
  readonly last_page: number;
}

export interface Paginated<T> {
  readonly data: readonly T[];
  readonly meta: PaginationMeta;
}

/** Contract section 1: `per_page` defaults to 25 and is capped at 100. */
export const DEFAULT_PER_PAGE = 25;
export const MAX_PER_PAGE = 100;

export const EMPTY_META: PaginationMeta = {
  current_page: 1,
  per_page: DEFAULT_PER_PAGE,
  total: 0,
  last_page: 1
};
