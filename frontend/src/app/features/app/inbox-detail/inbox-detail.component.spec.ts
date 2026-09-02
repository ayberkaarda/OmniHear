import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { environment } from '../../../../environments/environment';
import { FeedbackDetailStore } from '../../../core/feedback/feedback-detail.store';
import { makeAnalysis, makeFeedback } from '../../../core/feedback/feedback.fixtures';
import { InboxDetailComponent } from './inbox-detail.component';

const BASE = `${environment.apiBaseUrl}/v1/feedbacks`;

describe('InboxDetailComponent', () => {
  let fixture: ComponentFixture<InboxDetailComponent>;
  let element: HTMLElement;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      imports: [InboxDetailComponent],
      providers: [provideRouter([]), provideHttpClient(), provideHttpClientTesting()]
    });
    TestBed.inject(FeedbackDetailStore).reset();
    http = TestBed.inject(HttpTestingController);

    fixture = TestBed.createComponent(InboxDetailComponent);
    element = fixture.nativeElement as HTMLElement;
  });

  afterEach(() => http.verify());

  function load(id: string): void {
    fixture.componentRef.setInput('id', id);
    fixture.detectChanges();
  }

  it('fetches the record named by the route parameter', () => {
    load('7');
    expect(element.querySelector('[data-testid="detail-skeleton"]')).toBeTruthy();

    http.expectOne(`${BASE}/7`).flush({ feedback: makeFeedback({ id: 7 }) });
    fixture.detectChanges();

    expect(element.querySelector('[data-testid="detail-body"]')?.textContent).toContain('crashes every time');
  });

  it('shows every input behind the verdict, not just the verdict', () => {
    load('1');
    http.expectOne(`${BASE}/1`).flush({
      feedback: makeFeedback({
        analysis: makeAnalysis({ sentiment_score: -0.5497, confidence: 0.745, keywords: ['crash', 'login'] })
      })
    });
    fixture.detectChanges();

    const panel = element.querySelector('[data-testid="detail-analysis"]');
    expect(panel).toBeTruthy();

    const text = element.textContent ?? '';
    expect(text).toContain('-0.55');
    // The percent sign sits on either side depending on locale; the number is the assertion.
    expect(text).toMatch(/75\s*%|%\s*75/);
    expect(text).toContain('crash');
    expect(text).toContain('login');
    // A retrained analyser makes older scores incomparable; this is the only field that says which one ran.
    expect(text).toContain('omnihear-onnx-f50df013ccc9');
  });

  it('says the comment is queued rather than showing a zero score', () => {
    load('2');
    http
      .expectOne(`${BASE}/2`)
      .flush({ feedback: makeFeedback({ analysis_status: 'pending_analysis', analysis: null }) });
    fixture.detectChanges();

    expect(element.querySelector('[data-testid="detail-analysis"]')).toBeNull();
    expect(element.querySelector('[data-testid="detail-no-analysis"]')?.textContent).toContain('queued for analysis');
    expect(element.textContent).not.toContain('0.00');
  });

  it('distinguishes a failed analysis from one that has not started', () => {
    load('3');
    http.expectOne(`${BASE}/3`).flush({ feedback: makeFeedback({ analysis_status: 'failed', analysis: null }) });
    fixture.detectChanges();

    expect(element.querySelector('[data-testid="detail-no-analysis"]')?.textContent).toContain('failed');
  });

  it('renders the catalogue message for a cross-tenant 404, never the server string', () => {
    load('99');
    http
      .expectOne(`${BASE}/99`)
      .flush({ code: 'NOT_FOUND', message: 'No query results for model' }, { status: 404, statusText: 'Not Found' });
    fixture.detectChanges();

    const banner = element.querySelector('[data-testid="detail-error"]');
    expect(banner?.textContent).toContain('could not find what you were looking for');
    expect(banner?.textContent).not.toContain('No query results');
  });

  it('re-reads when the route parameter changes', () => {
    load('1');
    http.expectOne(`${BASE}/1`).flush({ feedback: makeFeedback({ id: 1 }) });
    fixture.detectChanges();

    load('2');
    http.expectOne(`${BASE}/2`).flush({ feedback: makeFeedback({ id: 2, body: 'a different comment' }) });
    fixture.detectChanges();

    expect(element.querySelector('[data-testid="detail-body"]')?.textContent).toContain('a different comment');
  });
});
