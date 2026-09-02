import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';

import { environment } from '../../../../environments/environment';
import { FeedbackListStore } from '../../../core/feedback/feedback-list.store';
import { makeFeedback, makeFeedbackPage } from '../../../core/feedback/feedback.fixtures';
import { makeIntegrationPage } from '../../../core/integrations/integration.fixtures';
import { InboxComponent } from './inbox.component';

const FEEDBACKS = `${environment.apiBaseUrl}/v1/feedbacks`;
const INTEGRATIONS = `${environment.apiBaseUrl}/v1/integrations`;

describe('InboxComponent', () => {
  let fixture: ComponentFixture<InboxComponent>;
  let element: HTMLElement;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [InboxComponent],
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()]
    });
    TestBed.inject(FeedbackListStore).reset();
    http = TestBed.inject(HttpTestingController);

    fixture = TestBed.createComponent(InboxComponent);
    element = fixture.nativeElement as HTMLElement;
    fixture.detectChanges();
  });

  /** Answers the two reads the screen fires on entry. */
  function settle(page = makeFeedbackPage()): void {
    http.expectOne((candidate) => candidate.url === FEEDBACKS).flush(page);
    http.expectOne((candidate) => candidate.url === INTEGRATIONS).flush(makeIntegrationPage());
    fixture.detectChanges();
  }

  afterEach(() => {
    http.verify();
  });

  it('shows the table skeleton while the first page is in flight', () => {
    expect(element.querySelector('[data-testid="data-table-loading"]')).toBeTruthy();
    settle();
    expect(element.querySelector('[data-testid="data-table-ready"]')).toBeTruthy();
  });

  it('renders the label and the score for sentiment, never colour alone', () => {
    settle(makeFeedbackPage([makeFeedback()]));

    const row = element.querySelector('tbody tr');
    expect(row?.textContent).toContain('Negative');
    expect(row?.textContent).toContain('-0.55');
    expect(row?.textContent).toContain('App Store');
  });

  it('leaves the analysis columns blank rather than inventing a neutral score', () => {
    settle(makeFeedbackPage([makeFeedback({ analysis_status: 'pending_analysis', analysis: null })]));

    const row = element.querySelector('tbody tr');
    expect(row?.textContent).toContain('Waiting for analysis');
    expect(row?.textContent).not.toContain('0.00');
  });

  it('debounces the search box into a single request carrying q', () => {
    jest.useFakeTimers();
    try {
      settle();

      const search = element.querySelector('input[type="search"]') as HTMLInputElement;
      search.value = 'cra';
      search.dispatchEvent(new Event('input'));
      search.value = 'crash';
      search.dispatchEvent(new Event('input'));

      http.expectNone((candidate) => candidate.params.has('q'));
      jest.advanceTimersByTime(400);

      const request = http.expectOne((candidate) => candidate.params.get('q') === 'crash');
      expect(request.request.params.get('page')).toBe('1');
      request.flush(makeFeedbackPage([]));
      fixture.detectChanges();
    } finally {
      jest.useRealTimers();
    }
  });

  it('turns a select back to "any" into an absent parameter, not an empty one', () => {
    settle();

    const sentiment = element.querySelectorAll('select')[0] as HTMLSelectElement;
    sentiment.value = 'negative';
    sentiment.dispatchEvent(new Event('change'));

    http.expectOne((candidate) => candidate.params.get('sentiment') === 'negative').flush(makeFeedbackPage([]));
    fixture.detectChanges();

    sentiment.value = '';
    sentiment.dispatchEvent(new Event('change'));

    const cleared = http.expectOne((candidate) => candidate.url === FEEDBACKS);
    expect(cleared.request.params.has('sentiment')).toBe(false);
    cleared.flush(makeFeedbackPage());
    fixture.detectChanges();
  });

  it('offers to clear the filters from the empty state when filters are what emptied it', () => {
    settle();

    const sentiment = element.querySelectorAll('select')[0] as HTMLSelectElement;
    sentiment.value = 'positive';
    sentiment.dispatchEvent(new Event('change'));
    http.expectOne((candidate) => candidate.url === FEEDBACKS).flush(makeFeedbackPage([], { total: 0 }));
    fixture.detectChanges();

    const empty = element.querySelector('[data-testid="data-table-empty"]');
    expect(empty?.textContent).toContain('No comment matches these filters');

    (empty?.querySelector('button') as HTMLButtonElement).click();
    const cleared = http.expectOne((candidate) => candidate.url === FEEDBACKS);
    expect(cleared.request.params.has('sentiment')).toBe(false);
    cleared.flush(makeFeedbackPage());
    fixture.detectChanges();
  });

  it('renders the error code message and retries from it', () => {
    http
      .expectOne((candidate) => candidate.url === FEEDBACKS)
      .flush({ code: 'SERVER_ERROR', message: 'raw server text' }, { status: 500, statusText: 'Server Error' });
    http.expectOne((candidate) => candidate.url === INTEGRATIONS).flush(makeIntegrationPage());
    fixture.detectChanges();

    const banner = element.querySelector('[data-testid="inbox-error"]');
    // The catalogue message for the code, never the server's own English text.
    expect(banner?.textContent).toContain('Something went wrong on our side');
    expect(banner?.textContent).not.toContain('raw server text');

    (banner?.querySelector('button') as HTMLButtonElement).click();
    http.expectOne((candidate) => candidate.url === FEEDBACKS).flush(makeFeedbackPage());
    fixture.detectChanges();
  });

  it('navigates to the detail route when a row is activated', () => {
    settle(makeFeedbackPage([makeFeedback({ id: 42 })]));

    const router = TestBed.inject(Router);
    const navigate = jest.spyOn(router, 'navigate').mockResolvedValue(true);

    (element.querySelector('tbody tr') as HTMLElement).click();

    expect(navigate).toHaveBeenCalledWith(['/app/inbox', 42]);
  });

  it('walks pages through the contract meta', () => {
    settle(makeFeedbackPage([makeFeedback()], { current_page: 1, last_page: 3, total: 60 }));

    expect(element.querySelector('[data-testid="inbox-pagination-summary"]')?.textContent).toContain('60');

    const buttons = Array.from(element.querySelectorAll('nav button')) as HTMLButtonElement[];
    const previous = buttons[buttons.length - 2];
    const next = buttons[buttons.length - 1];
    expect(previous.disabled).toBe(true);
    expect(next.disabled).toBe(false);

    next.click();
    const request = http.expectOne((candidate) => candidate.url === FEEDBACKS);
    expect(request.request.params.get('page')).toBe('2');
    request.flush(makeFeedbackPage([makeFeedback()], { current_page: 2, last_page: 3, total: 60 }));
    fixture.detectChanges();
  });
});
