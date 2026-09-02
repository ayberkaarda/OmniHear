import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { environment } from '../../../environments/environment';
import { makeKpis } from '../feedback/feedback.fixtures';
import { OverviewStore } from './overview.store';

const URL = `${environment.apiBaseUrl}/v1/overview/kpis`;

describe('OverviewStore', () => {
  let store: OverviewStore;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()]
    });
    store = TestBed.inject(OverviewStore);
    http = TestBed.inject(HttpTestingController);
    store.reset();
  });

  afterEach(() => http.verify());

  it('holds null rather than zero until the first answer arrives', () => {
    expect(store.kpis()).toBeNull();
    expect(store.loading()).toBe(true);

    store.load();
    http.expectOne(URL).flush(makeKpis());

    expect(store.loading()).toBe(false);
    expect(store.kpis()?.total_feedbacks).toBe(12);
    expect(store.trend()).toHaveLength(2);
  });

  it('separates "nothing collected" from "loaded"', () => {
    store.load();
    http.expectOne(URL).flush(makeKpis({ total_feedbacks: 0 }));

    expect(store.isEmpty()).toBe(true);
  });

  it('records the error code and leaves the numbers null', () => {
    store.load();
    http
      .expectOne(URL)
      .flush({ code: 'FORBIDDEN', message: 'nope' }, { status: 403, statusText: 'Forbidden' });

    expect(store.state()).toBe('error');
    expect(store.errorCode()).toBe('FORBIDDEN');
    expect(store.kpis()).toBeNull();
  });
});
