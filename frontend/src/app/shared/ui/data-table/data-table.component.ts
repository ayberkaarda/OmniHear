import { ChangeDetectionStrategy, Component, computed, input, output, signal } from '@angular/core';

import { IconComponent } from '../icon/icon.component';
import { ColumnDef, DataTableState, EmptyStateConfig, SortDirection, SortState } from './data-table.types';

const ROW_HEIGHT_CLASSES: Record<40 | 44, string> = {
  40: 'h-10',
  44: 'h-11'
};

const DEFAULT_MIN_WIDTH = 60;

function defaultRowId<T>(row: T): string {
  const candidate = (row as Record<string, unknown>)?.['id'];
  return candidate === undefined || candidate === null ? '' : String(candidate);
}

@Component({
  selector: 'app-data-table',
  standalone: true,
  imports: [IconComponent],
  templateUrl: './data-table.component.html',
  styleUrl: './data-table.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class DataTableComponent<T> {
  readonly columns = input.required<ColumnDef<T>[]>();
  readonly rows = input.required<T[]>();
  readonly rowHeight = input<40 | 44>(40);
  readonly state = input<DataTableState>('ready');
  readonly emptyState = input<EmptyStateConfig | undefined>(undefined);
  readonly selectable = input(true);
  readonly selection = input<ReadonlySet<string>>(new Set());
  readonly sort = input<SortState | undefined>(undefined);
  readonly stickyHeader = input(true);
  readonly highlightIds = input<ReadonlySet<string>>(new Set());

  /**
   * NOT part of the mandated API. Generic rows need a stable string id for
   * `selection`/`highlightIds`; defaults to `row.id` when present.
   */
  readonly rowId = input<(row: T) => string>(defaultRowId);

  /**
   * Reserved for a future virtualized rendering mode. Deliberately NOT
   * implemented in this pass — see SPEC: "sanal scroll bu turda kurma".
   * TODO: wire up viewport-windowed rendering (e.g. CDK virtual scroll or a
   * hand-rolled windowing strategy) when the inbox table needs it at scale.
   */
  readonly virtualScroll = input(false);

  readonly sortChange = output<SortState>();
  readonly selectionChange = output<ReadonlySet<string>>();
  readonly rowActivate = output<T>();
  readonly retry = output<void>();

  /** NOT part of the mandated API — lets the empty-state action button do something. */
  readonly emptyStateAction = output<void>();

  protected readonly rowHeightClass = computed(() => ROW_HEIGHT_CLASSES[this.rowHeight()]);

  private readonly resizedWidths = signal<Record<string, number>>({});

  protected readonly allSelected = computed(() => {
    if (!this.selectable() || this.rows().length === 0) {
      return false;
    }
    const selection = this.selection();
    return this.rows().every((row) => selection.has(this.rowId()(row)));
  });

  protected readonly someSelected = computed(() => {
    if (!this.selectable()) {
      return false;
    }
    const selection = this.selection();
    return this.rows().some((row) => selection.has(this.rowId()(row))) && !this.allSelected();
  });

  protected columnWidth(column: ColumnDef<T>): number | null {
    const resized = this.resizedWidths()[column.key];
    if (resized !== undefined) {
      return resized;
    }
    return column.width ?? null;
  }

  protected cellValue(column: ColumnDef<T>, row: T): string {
    if (column.cell) {
      return column.cell(row);
    }
    const raw = (row as Record<string, unknown>)?.[column.key];
    return raw === undefined || raw === null ? '' : String(raw);
  }

  protected ariaSort(column: ColumnDef<T>): 'ascending' | 'descending' | 'none' {
    if (!column.sortable) {
      return 'none';
    }
    const current = this.sort();
    if (!current || current.key !== column.key) {
      return 'none';
    }
    return current.dir === 'asc' ? 'ascending' : 'descending';
  }

  protected isSelected(row: T): boolean {
    return this.selection().has(this.rowId()(row));
  }

  protected isHighlighted(row: T): boolean {
    return this.highlightIds().has(this.rowId()(row));
  }

  protected onHeaderClick(column: ColumnDef<T>): void {
    if (!column.sortable) {
      return;
    }
    const current = this.sort();
    const nextDir: SortDirection = current && current.key === column.key && current.dir === 'asc' ? 'desc' : 'asc';
    this.sortChange.emit({ key: column.key, dir: nextDir });
  }

  protected onToggleAll(checked: boolean): void {
    const next = new Set<string>(checked ? this.rows().map((row) => this.rowId()(row)) : []);
    this.selectionChange.emit(next);
  }

  protected onToggleRow(row: T, checked: boolean): void {
    const id = this.rowId()(row);
    const next = new Set(this.selection());
    if (checked) {
      next.add(id);
    } else {
      next.delete(id);
    }
    this.selectionChange.emit(next);
  }

  protected onRowActivate(row: T): void {
    this.rowActivate.emit(row);
  }

  protected onRetry(): void {
    this.retry.emit();
  }

  protected onEmptyStateAction(): void {
    this.emptyStateAction.emit();
  }

  protected startResize(pointerEvent: MouseEvent, column: ColumnDef<T>): void {
    if (!column.resizable) {
      return;
    }
    pointerEvent.preventDefault();
    pointerEvent.stopPropagation();

    const startX = pointerEvent.clientX;
    const startWidth = this.columnWidth(column) ?? DEFAULT_MIN_WIDTH * 2;
    const minWidth = column.min ?? DEFAULT_MIN_WIDTH;

    const onMove = (moveEvent: MouseEvent): void => {
      const delta = moveEvent.clientX - startX;
      const nextWidth = Math.max(minWidth, Math.round(startWidth + delta));
      this.resizedWidths.update((widths) => ({ ...widths, [column.key]: nextWidth }));
    };

    const onUp = (): void => {
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);
    };

    document.addEventListener('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
  }
}
