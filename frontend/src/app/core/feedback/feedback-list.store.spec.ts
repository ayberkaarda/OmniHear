import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { environment } from '../../../environments/environment';
import { FeedbackListStore } from './feedback-list.store';
import { makeFeedback, makeFeedbackPage } from './feedback.fixtures';

const BASE = `${environment.apiBaseUrl}/v1/feedbacks`;

describe('FeedbackListStore', () => {
  let store: FeedbackListStore;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()]
    });
    store = TestBed.inject(FeedbackListStore);
    http = TestBed.inject(HttpTestingController);
    store.reset();
  });

  afterEach(() => http.verify());

  it('starts idle and only fetches once through loadIfNeeded', () => {
    expect(store.state()).toBe('idle');

    store.loadIfNeeded();
    http.expectOne((candidate) => candidate.url === BASE).flush(makeFeedbackPage());
    expect(store.state()).toBe('ready');

    store.loadIfNeeded();
    http.expectNone((candidate) => candidate.url === BASE);
  });

  it('reports an empty result set as empty, not as still loading', () => {
    store.load();
    expect(store.state()).toBe('loading');

    http.expectOne((candidate) => candidate.url === BASE).flush(makeFeedbackPage([], { total: 0 }));

    expect(store.state()).toBe('ready');
    expect(store.isEmpty()).toBe(true);
  });

  it('returns to page 1 when a filter changes', () => {
    store.load();
    http
      .expectOne((candidate) => candidate.url === BASE)
      .flush(makeFeedbackPage([makeFeedback()], { current_page: 3, last_page: 5 }));
    expect(store.page()).toBe(3);

    store.setFilters({ sentiment: 'negative' });

    const request = http.expectOne((candidate) => candidate.url === BASE);
    expect(request.request.params.get('page')).toBe('1');
    expect(request.request.params.get('sentiment')).toBe('negative');
    expect(store.hasFilters()).toBe(true);
    request.flush(makeFeedbackPage());
  });

  it('clamps a page request to the last page the server reported', () => {
    store.load();
    http.expectOne((candidate) => candidate.url === BASE).flush(makeFeedbackPage([makeFeedback()], { last_page: 2 }));

    store.setPage(99);
    const request = http.expectOne((candidate) => candidate.url === BASE);
    expect(request.request.params.get('page')).toBe('2');
    request.flush(makeFeedbackPage([makeFeedback()], { current_page: 2, last_page: 2 }));

    expect(store.canNext()).toBe(false);
    expect(store.canPrevious()).toBe(true);
  });

  it('drops a superseded response so the list cannot snap back to an older filter', () => {
    store.load();
    const first = http.expectOne((candidate) => candidate.url === BASE);

    store.setFilters({ q: 'crash' });
    const second = http.expectOne((candidate) => candidate.params.get('q') === 'crash');

    // Answered out of order: the newer request lands first, then the stale one.
    second.flush(makeFeedbackPage([makeFeedback({ id: 2, body: 'newer' })]));
    first.flush(makeFeedbackPage([makeFeedback({ id: 1, body: 'stale' })]));

    expect(store.items().map((item) => item.id)).toEqual([2]);
    expect(store.state()).toBe('ready');
  });

  it('keeps the rows already on screen when a refresh fails, and records the code', () => {
    store.load();
    http.expectOne((candidate) => candidate.url === BASE).flush(makeFeedbackPage([makeFeedback({ id: 5 })]));

    store.setFilters({ category: 'bug' });
    http
      .expectOne((candidate) => candidate.url === BASE)
      .flush({ code: 'SERVER_ERROR', message: 'boom' }, { status: 500, statusText: 'Server Error' });

    expect(store.state()).toBe('error');
    expect(store.errorCode()).toBe('SERVER_ERROR');
    expect(store.items().map((item) => item.id)).toEqual([5]);
  });

  it('resets the page when the page size changes', () => {
    store.load();
    http.expectOne((candidate) => candidate.url === BASE).flush(makeFeedbackPage([], { current_page: 4, last_page: 9 }));

    store.setPerPage(100);
    const request = http.expectOne((candidate) => candidate.url === BASE);
    expect(request.request.params.get('per_page')).toBe('100');
    expect(request.request.params.get('page')).toBe('1');
    request.flush(makeFeedbackPage());
  });
});
