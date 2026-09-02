/**
 * Where a store's single outstanding read stands.
 *
 * `idle` is distinct from `ready`-with-no-rows on purpose: "we have not asked
 * yet" and "we asked and the tenant has nothing" are different screens, and
 * collapsing them is how a loading skeleton turns into a permanent empty state.
 */
export type RequestState = 'idle' | 'loading' | 'ready' | 'error';
