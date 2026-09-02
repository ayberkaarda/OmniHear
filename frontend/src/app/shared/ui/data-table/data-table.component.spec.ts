import { TestBed } from '@angular/core/testing';

import { DataTableComponent } from './data-table.component';
import { ColumnDef } from './data-table.types';

interface Row {
  id: string;
  name: string;
  score: number;
}

const COLUMNS: ColumnDef<Row>[] = [
  { key: 'name', header: 'Name', sortable: true, resizable: true, width: 120, min: 60 },
  { key: 'score', header: 'Score' }
];

const ROWS: Row[] = [
  { id: 'r1', name: 'Alice', score: 3 },
  { id: 'r2', name: 'Bob', score: 5 }
];

describe('DataTableComponent', () => {
  it('renders the loading state', async () => {
    await TestBed.configureTestingModule({ imports: [DataTableComponent] }).compileComponents();
    const fixture = TestBed.createComponent<DataTableComponent<Row>>(DataTableComponent);
    fixture.componentRef.setInput('columns', COLUMNS);
    fixture.componentRef.setInput('rows', []);
    fixture.componentRef.setInput('state', 'loading');
    fixture.detectChanges();
    await fixture.whenStable();

    expect((fixture.nativeElement as HTMLElement).querySelector('[data-testid="data-table-loading"]')).toBeTruthy();
  });

  it('renders the empty state with the provided title', async () => {
    await TestBed.configureTestingModule({ imports: [DataTableComponent] }).compileComponents();
    const fixture = TestBed.createComponent<DataTableComponent<Row>>(DataTableComponent);
    fixture.componentRef.setInput('columns', COLUMNS);
    fixture.componentRef.setInput('rows', []);
    fixture.componentRef.setInput('state', 'empty');
    fixture.componentRef.setInput('emptyState', { title: 'No feedback yet' });
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    expect(root.querySelector('[data-testid="data-table-empty"]')?.textContent).toContain('No feedback yet');
  });

  it('renders the error state and emits retry', async () => {
    await TestBed.configureTestingModule({ imports: [DataTableComponent] }).compileComponents();
    const fixture = TestBed.createComponent<DataTableComponent<Row>>(DataTableComponent);
    fixture.componentRef.setInput('columns', COLUMNS);
    fixture.componentRef.setInput('rows', []);
    fixture.componentRef.setInput('state', 'error');
    fixture.detectChanges();
    await fixture.whenStable();

    let retried = false;
    fixture.componentInstance.retry.subscribe(() => (retried = true));

    const root = fixture.nativeElement as HTMLElement;
    expect(root.querySelector('[data-testid="data-table-error"]')).toBeTruthy();
    (root.querySelector('[data-testid="data-table-error"] button') as HTMLButtonElement).click();
    await fixture.whenStable();

    expect(retried).toBe(true);
  });

  it('renders the ready state with rows', async () => {
    await TestBed.configureTestingModule({ imports: [DataTableComponent] }).compileComponents();
    const fixture = TestBed.createComponent<DataTableComponent<Row>>(DataTableComponent);
    fixture.componentRef.setInput('columns', COLUMNS);
    fixture.componentRef.setInput('rows', ROWS);
    fixture.detectChanges();
    await fixture.whenStable();

    const root = fixture.nativeElement as HTMLElement;
    expect(root.querySelectorAll('tbody tr').length).toBe(2);
  });

  it('emits sortChange with the toggled direction when a sortable header is clicked', async () => {
    await TestBed.configureTestingModule({ imports: [DataTableComponent] }).compileComponents();
    const fixture = TestBed.createComponent<DataTableComponent<Row>>(DataTableComponent);
    fixture.componentRef.setInput('columns', COLUMNS);
    fixture.componentRef.setInput('rows', ROWS);
    fixture.componentRef.setInput('sort', { key: 'name', dir: 'asc' });
    fixture.detectChanges();
    await fixture.whenStable();

    let emitted: unknown;
    fixture.componentInstance.sortChange.subscribe((value) => (emitted = value));

    const headerButton = (fixture.nativeElement as HTMLElement).querySelector('thead button') as HTMLButtonElement;
    headerButton.click();
    await fixture.whenStable();

    expect(emitted).toEqual({ key: 'name', dir: 'desc' });
  });

  it('adds the row-highlight class to rows present in highlightIds', async () => {
    await TestBed.configureTestingModule({ imports: [DataTableComponent] }).compileComponents();
    const fixture = TestBed.createComponent<DataTableComponent<Row>>(DataTableComponent);
    fixture.componentRef.setInput('columns', COLUMNS);
    fixture.componentRef.setInput('rows', ROWS);
    fixture.componentRef.setInput('highlightIds', new Set(['r2']));
    fixture.detectChanges();
    await fixture.whenStable();

    const rowEls = (fixture.nativeElement as HTMLElement).querySelectorAll('tbody tr');
    expect(rowEls[0].className).not.toContain('row-highlight');
    expect(rowEls[1].className).toContain('row-highlight');
  });

  it('emits rowActivate when a row is clicked', async () => {
    await TestBed.configureTestingModule({ imports: [DataTableComponent] }).compileComponents();
    const fixture = TestBed.createComponent<DataTableComponent<Row>>(DataTableComponent);
    fixture.componentRef.setInput('columns', COLUMNS);
    fixture.componentRef.setInput('rows', ROWS);
    fixture.detectChanges();
    await fixture.whenStable();

    let activated: Row | undefined;
    fixture.componentInstance.rowActivate.subscribe((row: Row) => (activated = row));

    const firstRow = (fixture.nativeElement as HTMLElement).querySelector('tbody tr') as HTMLTableRowElement;
    firstRow.click();
    await fixture.whenStable();

    expect(activated).toEqual(ROWS[0]);
  });

  it('emits selectionChange with the toggled row id', async () => {
    await TestBed.configureTestingModule({ imports: [DataTableComponent] }).compileComponents();
    const fixture = TestBed.createComponent<DataTableComponent<Row>>(DataTableComponent);
    fixture.componentRef.setInput('columns', COLUMNS);
    fixture.componentRef.setInput('rows', ROWS);
    fixture.detectChanges();
    await fixture.whenStable();

    let emitted: ReadonlySet<string> | undefined;
    fixture.componentInstance.selectionChange.subscribe((value: ReadonlySet<string>) => (emitted = value));

    const firstCheckbox = (fixture.nativeElement as HTMLElement).querySelector(
      'tbody input[type="checkbox"]'
    ) as HTMLInputElement;
    firstCheckbox.checked = true;
    firstCheckbox.dispatchEvent(new Event('change'));
    await fixture.whenStable();

    expect(emitted).toBeTruthy();
    expect(Array.from(emitted as ReadonlySet<string>)).toEqual(['r1']);
  });
});
