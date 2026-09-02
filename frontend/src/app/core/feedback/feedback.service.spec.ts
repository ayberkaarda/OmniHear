import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';

import { environment } from '../../../environments/environment';
import { EMPTY_FILTERS } from './feedback.models';
import { makeFeedback, makeFeedbackPage } from './feedback.fixtures';
import { FeedbackService } from './feedback.service';

const BASE = `${environment.apiBaseUrl}/v1/feedbacks`;

describe('FeedbackService', () => {
  let service: FeedbackService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()]
    });
    service = TestBed.inject(FeedbackService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('sends only page and per_page when no filter is set', () => {
    service.list(EMPTY_FILTERS, 1, 25).subscribe();

    const request = http.expectOne((candidate) => candidate.url === BASE);
    expect(request.request.method).toBe('GET');
    expect(request.request.params.keys().sort()).toEqual(['page', 'per_page']);
    request.flush(makeFeedbackPage());
  });

  it('omits an empty filter rather than sending it blank', () => {
    // A blank value is not "no filter": every filter is validated `sometimes`
    // on the server, so `sentiment=` fails Rule::in and 422s the whole read.
    service.list({ ...EMPTY_FILTERS, sentiment: 'negative', q: '' as unknown as null }, 2, 50).subscribe();

    const request = http.expectOne((candidate) => candidate.url === BASE);
    expect(request.request.params.get('sentiment')).toBe('negative');
    expect(request.request.params.has('q')).toBe(false);
    expect(request.request.params.get('page')).toBe('2');
    expect(request.request.params.get('per_page')).toBe('50');
    request.flush(makeFeedbackPage());
  });

  it('serializes every contract filter it is given', () => {
    service
      .list(
        {
          sentiment: 'positive',
          category: 'bug',
          platform: 'appstore',
          integration_id: 7,
          analysis_status: 'analyzed',
          from: '2026-08-01',
          to: '2026-09-01',
          q: 'crash'
        },
        1,
        25
      )
      .subscribe();

    const request = http.expectOne((candidate) => candidate.url === BASE);
    expect(request.request.params.keys().sort()).toEqual([
      'analysis_status',
      'category',
      'from',
      'integration_id',
      'page',
      'per_page',
      'platform',
      'q',
      'sentiment',
      'to'
    ]);
    expect(request.request.params.get('integration_id')).toBe('7');
    request.flush(makeFeedbackPage());
  });

  it('unwraps the named single-resource key', () => {
    // Contract section 1: a single resource has no `data` wrapper.
    let received: unknown = null;
    service.get(9).subscribe((feedback) => (received = feedback));

    const request = http.expectOne(`${BASE}/9`);
    request.flush({ feedback: makeFeedback({ id: 9 }) });

    expect(received).toEqual(makeFeedback({ id: 9 }));
  });
});
