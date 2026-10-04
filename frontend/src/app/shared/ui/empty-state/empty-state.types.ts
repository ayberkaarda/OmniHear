export interface ColumnDef<T> {
  key: string;
  header: string;
  width?: number;
  min?: number;
  sortable?: boolean;
  resizable?: boolean;
  /** Optional custom cell renderer. Falls back to String(row[key]) when omitted. */
  cell?: (row: T) => string;
}

export type SortDirection = 'asc' | 'desc';

export interface SortState {
  key: string;
  dir: SortDirection;
}

export interface EmptyStateConfig {
  title: string;
  description?: string;
  actionLabel?: string;
}

export type DataTableState = 'ready' | 'loading' | 'empty' | 'error';

/** Sentiment of a row, drawn as the 6 px edge; `pending` is a comment not read yet. */
export type DataTableRowTone = 'positive' | 'neutral' | 'negative' | 'pending';
